<?php
header('Content-Type: application/json');
session_start();

$action = $_GET['action'] ?? '';
$data_dir = __DIR__ . '/data';

if (!file_exists($data_dir)) {
    mkdir($data_dir, 0777, true);
}

function read_json($file) {
    global $data_dir;
    $path = "$data_dir/$file";
    if (!file_exists($path)) return [];
    $content = file_get_contents($path);
    return json_decode($content, true) ?? [];
}

function write_json($file, $data) {
    global $data_dir;
    $path = "$data_dir/$file";
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function normalize_string($str) {
    if (!is_string($str)) return '';
    $str = mb_strtolower(trim($str), 'UTF-8');
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    $str = preg_replace('/[^a-z0-9]/', '', $str);
    return $str;
}

$dictionary = [];
if (file_exists($data_dir . '/dictionary.json')) {
    $dictionary = json_decode(file_get_contents($data_dir . '/dictionary.json'), true) ?? [];
}

if (rand(1, 20) == 1) {
    $users = read_json('users.json');
    $now = time();
    $active_users = array_filter($users, function($u) use ($now) {
        return ($now - $u['last_seen']) < 300;
    });
    write_json('users.json', array_values($active_users));
}

switch ($action) {
    case 'login':
        $username = htmlspecialchars(trim($_POST['username'] ?? ''));
        if (!$username) {
            echo json_encode(['success' => false, 'message' => 'Username required']);
            exit;
        }

        $users = read_json('users.json');
        $user_id = uniqid('user_');

        $new_user = [
            'id'        => $user_id,
            'name'      => $username,
            'status'    => 'online',
            'last_seen' => time(),
            'game_id'   => null
        ];

        $users[] = $new_user;
        write_json('users.json', $users);

        $_SESSION['user_id']  = $user_id;
        $_SESSION['username'] = $username;

        echo json_encode(['success' => true, 'user' => $new_user]);
        break;

    case 'whoami':
        if (isset($_SESSION['user_id'])) {
            $my_id = $_SESSION['user_id'];
            $users = read_json('users.json');
            foreach ($users as $u) {
                if ($u['id'] === $my_id) {
                    echo json_encode(['success' => true, 'user' => $u]);
                    exit;
                }
            }
        }
        echo json_encode(['success' => false]);
        break;

    case 'list_users':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false]);
            exit;
        }

        $users = read_json('users.json');
        $my_id = $_SESSION['user_id'];
        $me    = null;

        foreach ($users as &$u) {
            if ($u['id'] === $my_id) {
                $u['last_seen'] = time();
                $me = $u;
            }
        }
        unset($u);
        write_json('users.json', $users);

        $others = array_values(array_filter($users, function($u) use ($my_id) {
            return $u['id'] !== $my_id && $u['status'] === 'online';
        }));

        echo json_encode(['success' => true, 'users' => $others, 'me' => $me]);
        break;

    case 'invite':
        $target_id = $_POST['target_id'] ?? '';
        $my_id     = $_SESSION['user_id'] ?? '';
        $my_name   = $_SESSION['username'] ?? '';

        if (!$target_id || !$my_id) exit;

        $users = read_json('users.json');
        $my_game_id = null;
        foreach ($users as $u) {
            if ($u['id'] === $my_id) { $my_game_id = $u['game_id']; break; }
        }

        $games = read_json('games.json');

        if (!$my_game_id || !isset($games[$my_game_id])) {
            $my_game_id = uniqid('game_');
            $games[$my_game_id] = [
                'id'               => $my_game_id,
                'host_id'          => $my_id,
                'players'          => [
                    $my_id => ['score' => 0, 'answers' => [], 'status' => 'ready', 'name' => $my_name]
                ],
                'state'            => 'lobby',
                'current_round'    => 0,
                'total_rounds'     => 10,
                'current_letter'   => '',
                'letters_sequence' => [],
                'timer_start'      => 0,
                'round_duration'   => 60
            ];

            foreach ($users as &$u) {
                if ($u['id'] === $my_id) {
                    $u['game_id'] = $my_game_id;
                    $u['status']  = 'in_lobby';
                }
            }
            unset($u);
            write_json('users.json', $users);
            write_json('games.json', $games);
        } else {
            if ($games[$my_game_id]['host_id'] !== $my_id) {
                echo json_encode(['success' => false, 'message' => 'Seul l\'hôte peut inviter.']);
                exit;
            }
        }

        $invites   = read_json('invites.json');
        $invites[] = [
            'id'        => uniqid('inv_'),
            'from_id'   => $my_id,
            'from_name' => $my_name,
            'to_id'     => $target_id,
            'game_id'   => $my_game_id,
            'status'    => 'pending',
            'timestamp' => time()
        ];
        write_json('invites.json', $invites);

        echo json_encode(['success' => true]);
        break;

    case 'get_invites':
        $my_id = $_SESSION['user_id'] ?? '';
        if (!$my_id) exit;

        $invites    = read_json('invites.json');
        $my_invites = [];

        foreach ($invites as $k => $inv) {
            if ($inv['to_id'] === $my_id && $inv['status'] === 'pending') {
                $my_invites[] = $inv;
            }
            if ($inv['from_id'] === $my_id && $inv['status'] !== 'pending') {
                unset($invites[$k]);
            }
        }
        write_json('invites.json', array_values($invites));

        echo json_encode(['success' => true, 'invites' => $my_invites]);
        break;

    case 'respond_invite':
        $invite_id = $_POST['invite_id'] ?? '';
        $response  = $_POST['response'] ?? '';
        $my_id     = $_SESSION['user_id'] ?? '';
        $my_name   = $_SESSION['username'] ?? 'Joueur';

        // Whitelist — never write arbitrary strings into the JSON
        if (!in_array($response, ['accepted', 'declined'], true) || !$my_id) exit;

        $invites        = read_json('invites.json');
        $target_game_id = null;

        foreach ($invites as &$inv) {
            if ($inv['id'] === $invite_id && $inv['to_id'] === $my_id) {
                $inv['status']  = $response;
                $target_game_id = $inv['game_id'] ?? null;
            }
        }
        unset($inv);
        write_json('invites.json', $invites);

        if ($response === 'accepted' && $target_game_id) {
            $games = read_json('games.json');
            if (isset($games[$target_game_id]) && $games[$target_game_id]['state'] === 'lobby') {
                $games[$target_game_id]['players'][$my_id] = [
                    'score'   => 0,
                    'answers' => [],
                    'status'  => 'ready',
                    'name'    => $my_name
                ];
                write_json('games.json', $games);

                $users = read_json('users.json');
                foreach ($users as &$u) {
                    if ($u['id'] === $my_id) {
                        $u['game_id'] = $target_game_id;
                        $u['status']  = 'in_lobby';
                    }
                }
                unset($u);
                write_json('users.json', $users);
            }
        }

        echo json_encode(['success' => true]);
        break;

    case 'start_game_host':
        $my_id  = $_SESSION['user_id'] ?? '';
        if (!$my_id) exit;

        $rounds = max(3, min(26, intval($_POST['rounds'] ?? 10)));

        $users   = read_json('users.json');
        $game_id = null;
        foreach ($users as $u) {
            if ($u['id'] === $my_id) { $game_id = $u['game_id']; break; }
        }

        if (!$game_id) exit;

        $games = read_json('games.json');
        if (!isset($games[$game_id])) exit;

        if ($games[$game_id]['host_id'] !== $my_id) {
            echo json_encode(['success' => false, 'message' => 'Not Host']);
            exit;
        }

        // Shuffle the alphabet so every game has a different letter order
        $alphabet = range('A', 'Z');
        shuffle($alphabet);
        $letters_sequence = array_slice($alphabet, 0, $rounds);

        $games[$game_id]['total_rounds']     = $rounds;
        $games[$game_id]['letters_sequence'] = $letters_sequence;
        $games[$game_id]['current_letter']   = $letters_sequence[0];
        $games[$game_id]['state']            = 'playing';
        $games[$game_id]['timer_start']      = time();
        $games[$game_id]['current_round']    = 0;

        foreach ($users as &$u) {
            if (isset($games[$game_id]['players'][$u['id']])) {
                $u['status'] = 'in_game';
            }
        }
        unset($u);
        write_json('users.json', $users);
        write_json('games.json', $games);

        echo json_encode(['success' => true]);
        break;

    case 'leave_game':
        $my_id = $_SESSION['user_id'] ?? '';
        if (!$my_id) exit;

        $users   = read_json('users.json');
        $game_id = null;

        foreach ($users as &$u) {
            if ($u['id'] === $my_id) {
                $game_id     = $u['game_id'];
                $u['game_id'] = null;
                $u['status']  = 'online';
            }
        }
        unset($u);
        write_json('users.json', $users);

        if ($game_id) {
            $games = read_json('games.json');
            if (isset($games[$game_id])) {
                if ($games[$game_id]['state'] === 'lobby') {
                    unset($games[$game_id]['players'][$my_id]);
                    if (empty($games[$game_id]['players'])) {
                        unset($games[$game_id]);
                    }
                } else {
                    if (isset($games[$game_id]['players'][$my_id])) {
                        $games[$game_id]['players'][$my_id]['status'] = 'left';
                        $games[$game_id]['players'][$my_id]['name']  .= ' (Abandon)';
                    }

                    $active_count = 0;
                    foreach ($games[$game_id]['players'] as $p) {
                        if ($p['status'] !== 'left') $active_count++;
                    }

                    if ($active_count === 0) {
                        unset($games[$game_id]);
                    } elseif ($active_count === 1 && count($games[$game_id]['players']) > 1) {
                        $games[$game_id]['state'] = 'finished';
                    }
                }
                write_json('games.json', $games);
            }
        }
        echo json_encode(['success' => true]);
        break;

    case 'game_poll':
        $my_id = $_SESSION['user_id'] ?? '';
        if (!$my_id) {
            echo json_encode(['error' => 'Not logged in']);
            exit;
        }

        $users   = read_json('users.json');
        $my_user = null;
        foreach ($users as $u) {
            if ($u['id'] === $my_id) { $my_user = $u; break; }
        }

        if (!$my_user || !$my_user['game_id']) {
            echo json_encode(['state' => 'idle']);
            exit;
        }

        $game_id = $my_user['game_id'];
        $games   = read_json('games.json');
        $game    = $games[$game_id] ?? null;

        if (!$game) {
            echo json_encode(['state' => 'ended']);
            exit;
        }

        if ($game['state'] === 'lobby') {
            echo json_encode([
                'state'   => 'lobby',
                'game'    => $game,
                'is_host' => ($game['host_id'] === $my_id)
            ]);
            exit;
        }

        if ($game['state'] === 'playing') {
            $elapsed   = time() - $game['timer_start'];
            $remaining = max(0, $game['round_duration'] - $elapsed);

            if ($remaining <= 0) {
                $game['state']        = 'evaluating';
                $games[$game_id]      = $game;
                write_json('games.json', $games);
            }
            $game['time_remaining'] = $remaining;
        }

        echo json_encode(['success' => true, 'game' => $game]);
        break;

    case 'submit_answers':
        $game_id = $_POST['game_id'] ?? '';
        $answers = $_POST['answers'] ?? [];
        $my_id   = $_SESSION['user_id'] ?? '';

        if (!$my_id || !$game_id) exit;

        $games = read_json('games.json');
        if (!isset($games[$game_id])) exit;

        // Verify the caller is actually a player in this game
        if (!isset($games[$game_id]['players'][$my_id])) exit;

        $letter      = $games[$game_id]['current_letter'];
        $score_round = 0;

        foreach ($answers as $cat => $val) {
            $raw_val = trim($val);
            if (empty($raw_val)) continue;

            $normalized_val = normalize_string($raw_val);
            $target_letter  = normalize_string($letter);
            $is_valid       = false;

            if (isset($dictionary[$cat])) {
                foreach ($dictionary[$cat] as $word) {
                    $norm_word = normalize_string($word);
                    if (substr($norm_word, 0, 1) !== $target_letter) continue;

                    if ($norm_word === $normalized_val) {
                        $is_valid = true;
                        break;
                    }

                    $limit = (strlen($norm_word) < 5) ? 1 : 2;
                    if (levenshtein($normalized_val, $norm_word) <= $limit) {
                        $is_valid = true;
                        break;
                    }
                }
            }

            if ($is_valid) {
                $score_round += 0.5;
            } elseif (substr($normalized_val, 0, 1) === $target_letter) {
                $score_round += 0.5;
            }
        }

        $games[$game_id]['players'][$my_id]['score']  += $score_round;
        $games[$game_id]['players'][$my_id]['status']  = 'submitted';

        // All active players submitted?
        $all_submitted = true;
        foreach ($games[$game_id]['players'] as $pdata) {
            if ($pdata['status'] !== 'submitted' && $pdata['status'] !== 'left') {
                $all_submitted = false;
                break;
            }
        }

        if ($all_submitted) {
            $games[$game_id]['current_round']++;
            foreach ($games[$game_id]['players'] as &$p) {
                if ($p['status'] !== 'left') $p['status'] = 'ready';
            }
            unset($p);

            if ($games[$game_id]['current_round'] >= $games[$game_id]['total_rounds']) {
                $games[$game_id]['state'] = 'finished';
            } else {
                $games[$game_id]['state']       = 'round_transition';
                $games[$game_id]['timer_start'] = time();
            }
        }

        write_json('games.json', $games);
        echo json_encode(['success' => true]);
        break;

    case 'transition_check':
        $my_id   = $_SESSION['user_id'] ?? '';
        $game_id = $_POST['game_id'] ?? '';
        if (!$my_id || !$game_id) exit;

        $games = read_json('games.json');
        if (!isset($games[$game_id])) exit;

        // Only players in this game can trigger a transition
        if (!isset($games[$game_id]['players'][$my_id])) exit;

        if ($games[$game_id]['state'] === 'round_transition') {
            if (time() - $games[$game_id]['timer_start'] > 5) {
                $idx            = $games[$game_id]['current_round'];
                $total          = $games[$game_id]['total_rounds'];
                $letters_seq    = $games[$game_id]['letters_sequence'] ?? range('A', 'Z');

                if ($idx >= $total) {
                    $games[$game_id]['state'] = 'finished';
                } else {
                    $games[$game_id]['current_letter'] = $letters_seq[$idx] ?? 'A';
                    $games[$game_id]['state']          = 'playing';
                    $games[$game_id]['timer_start']    = time();
                }
                write_json('games.json', $games);
            }
        }
        echo json_encode(['success' => true]);
        break;
}
?>
