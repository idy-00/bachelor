<?php
// api.php - Central API handler
header('Content-Type: application/json');
session_start();

$action = $_GET['action'] ?? '';
$data_dir = __DIR__ . '/data';

if (!file_exists($data_dir)) {
    mkdir($data_dir, 0777, true);
}

// Simple JSON DB helpers
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
    // Using simple file put contents with lock could be racy but sufficient for small scale
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function normalize_string($str) {
    if (!is_string($str)) return '';
    $str = mb_strtolower(trim($str), 'UTF-8');
    // Remove accents
    $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);
    // Remove non-alphanumeric
    $str = preg_replace('/[^a-z0-9]/', '', $str);
    return $str;
}

// Load dictionary once (or on demand)
$dictionary = [];
if (file_exists($data_dir . '/dictionary.json')) {
    $dictionary = json_decode(file_get_contents($data_dir . '/dictionary.json'), true) ?? [];
}

// Cleanup old users/games (garbage collection, runs occasionally)
if (rand(1, 20) == 1) {
    $users = read_json('users.json');
    $now = time();
    $active_users = array_filter($users, function($u) use ($now) {
        return ($now - $u['last_seen']) < 300; // 5 min timeout
    });
    write_json('users.json', array_values($active_users));
}

switch ($action) {
    case 'login':
        $username = htmlspecialchars($_POST['username'] ?? '');
        if (!$username) {
            echo json_encode(['success' => false, 'message' => 'Username required']);
            exit;
        }
        
        $users = read_json('users.json');
        $user_id = uniqid('user_');
        
        // Remove old entries with same name if any (or just create new)
        // For simplicity, we allow duplicate display names but unique IDs
        
        $new_user = [
            'id' => $user_id,
            'name' => $username,
            'status' => 'online', // online, in_game
            'last_seen' => time(),
            'game_id' => null
        ];
        
        $users[] = $new_user;
        write_json('users.json', $users);
        
        $_SESSION['user_id'] = $user_id;
        $_SESSION['username'] = $username;
        
        echo json_encode(['success' => true, 'user' => $new_user]);
        break;

    case 'whoami':
        if (isset($_SESSION['user_id'])) {
            $my_id = $_SESSION['user_id'];
            $users = read_json('users.json');
            $me = null;
            foreach ($users as $u) {
                if ($u['id'] === $my_id) {
                    $me = $u;
                    break;
                }
            }
            if ($me) {
                echo json_encode(['success' => true, 'user' => $me]);
                return;
            }
        }
        echo json_encode(['success' => false]);
        break;


    case 'list_users':
        if (!isset($_SESSION['user_id'])) {
            echo json_encode(['success' => false]);
            exit;
        }
        
        // Update current user heartbeat
        $users = read_json('users.json');
        $my_id = $_SESSION['user_id'];
        $me = null;
        
        foreach ($users as &$u) {
            if ($u['id'] === $my_id) {
                $u['last_seen'] = time();
                $me = $u;
            }
        }
        unset($u);
        write_json('users.json', $users);

        // Filter out myself
        $others = array_values(array_filter($users, function($u) use ($my_id) {
            return $u['id'] !== $my_id && $u['status'] === 'online';
        }));
        
        echo json_encode(['success' => true, 'users' => $others, 'me' => $me]);
        break;

    case 'invite':
        $target_id = $_POST['target_id'] ?? '';
        $my_id = $_SESSION['user_id'] ?? '';
        $my_name = $_SESSION['username'] ?? '';
        
        if (!$target_id || !$my_id) exit;
        
        // Check if I am in a game lobby?
        $users = read_json('users.json');
        $my_game_id = null;
        foreach($users as $u) {
            if ($u['id'] === $my_id) {
                $my_game_id = $u['game_id'];
                break;
            }
        }

        $games = read_json('games.json');

        // If I am NOT in a game, create one in 'lobby' state
        if (!$my_game_id || !isset($games[$my_game_id])) {
            $my_game_id = uniqid('game_');
            $games[$my_game_id] = [
                'id' => $my_game_id,
                'host_id' => $my_id,
                'players' => [
                    $my_id => ['score' => 0, 'answers' => [], 'status' => 'ready', 'name' => $my_name]
                ],
                'state' => 'lobby', 
                'current_round' => 0,
                'total_rounds' => 10,
                'current_letter' => 'A',
                'timer_start' => 0,
                'round_duration' => 60
            ];
            
            // Assign me to this game
             foreach ($users as &$u) {
                if ($u['id'] === $my_id) {
                    $u['game_id'] = $my_game_id;
                    $u['status'] = 'in_lobby';
                }
            }
            write_json('users.json', $users);
            write_json('games.json', $games);
        } else {
            // I am already in a game. Invite to THIS game.
            // Only host can invite? Let's say yes for now to avoid chaos.
            if ($games[$my_game_id]['host_id'] !== $my_id) {
                 echo json_encode(['success' => false, 'message' => 'Seul l\'hôte peut inviter.']); // Frontend handles errors?
                 exit;
            }
        }

        $invites = read_json('invites.json');
        // Check filtering duplicates...
        $invites[] = [
            'id' => uniqid('inv_'),
            'from_id' => $my_id,
            'from_name' => $my_name,
            'to_id' => $target_id,
            'game_id' => $my_game_id, // Link to the specific game
            'status' => 'pending', 
            'timestamp' => time()
        ];
        write_json('invites.json', $invites);
        
        echo json_encode(['success' => true]);
        break;

    case 'get_invites': // Poll for invites
        $my_id = $_SESSION['user_id'] ?? '';
        if (!$my_id) exit;
        
        $invites = read_json('invites.json');
        $my_invites = [];

        foreach ($invites as $k => $inv) {
            // Invites sent TO me
            if ($inv['to_id'] === $my_id && $inv['status'] === 'pending') {
                $my_invites[] = $inv;
            }
            // Check replies to invites I SENT. 
            // In the new Lobby logic, we don't necessarily need a special 'start_setup' action.
            // The polling of `list_users` or `game_poll` will show the new player in lobby.
            // So we can remove the 'accepted' check here, OR just cleanup used invites
            if ($inv['from_id'] === $my_id && $inv['status'] !== 'pending') {
                 // Just cleanup notification if needed
                 unset($invites[$k]);
            }
        }
        write_json('invites.json', array_values($invites));
        
        echo json_encode(['success' => true, 'invites' => $my_invites]);
        break;

    case 'respond_invite':
        $invite_id = $_POST['invite_id'] ?? '';
        $response = $_POST['response'] ?? ''; 
        $my_id = $_SESSION['user_id'];
        $my_name = $_SESSION['username'] ?? 'Joueur';
        
        $invites = read_json('invites.json');
        $target_game_id = null;

        foreach ($invites as &$inv) {
            if ($inv['id'] === $invite_id) {
                $inv['status'] = $response;
                $target_game_id = $inv['game_id'] ?? null;
            }
        }
        write_json('invites.json', $invites);
        
        if ($response === 'accepted' && $target_game_id) {
             // Add user to the game
             $games = read_json('games.json');
             if (isset($games[$target_game_id])) {
                 // Add player
                 $games[$target_game_id]['players'][$my_id] = [
                     'score' => 0, 
                     'answers' => [], 
                     'status' => 'ready',
                     'name' => $my_name
                 ];
                 write_json('games.json', $games);

                 // Update User status
                 $users = read_json('users.json');
                 foreach($users as &$u) {
                     if ($u['id'] === $my_id) {
                         $u['game_id'] = $target_game_id; // Added to lobby
                         $u['status'] = 'in_lobby';
                     }
                 }
                 write_json('users.json', $users);
             }
        }
        
        echo json_encode(['success' => true]);
        break;

    case 'create_game': 
        // Renamed/Repurposed to START GAME
        // Host clicks "Start"
        // But we kept case name for minimal churn? No, let's rename or add new.
        // Let's use 'start_game_now' to avoid conflict with old 'create_game' logic.
        break;

    case 'start_game_host':
        $my_id = $_SESSION['user_id'];
        $rounds = intval($_POST['rounds'] ?? 10);
        
        $users = read_json('users.json');
        $game_id = null;
        foreach($users as $u) {
            if ($u['id'] === $my_id) $game_id = $u['game_id'];
        }

        if (!$game_id) exit;

        $games = read_json('games.json');
        if (!isset($games[$game_id])) exit;

        // Verify Host
        if ($games[$game_id]['host_id'] !== $my_id) {
            echo json_encode(['success' => false, 'message' => 'Not Host']);
            exit;
        }

        // Setup Game
        $games[$game_id]['total_rounds'] = $rounds;
        
        // Pick first letter
        $alphabet = range('A', 'Z');
        $games[$game_id]['current_letter'] = $alphabet[0];
        $games[$game_id]['state'] = 'playing';
        $games[$game_id]['timer_start'] = time();
        $games[$game_id]['current_round'] = 0; // Reset just in case

        // Identify players and set them 'in_game'
        foreach($users as &$u) {
            if (isset($games[$game_id]['players'][$u['id']])) {
                $u['status'] = 'in_game';
            }
        }
        write_json('users.json', $users);
        write_json('games.json', $games);

        echo json_encode(['success' => true]);
        break;

    case 'leave_game':
        $my_id = $_SESSION['user_id'];
        $users = read_json('users.json');
        $game_id = null;

        foreach($users as &$u) {
            if ($u['id'] === $my_id) {
                $game_id = $u['game_id'];
                $u['game_id'] = null;
                $u['status'] = 'online';
                // Remove from game?
            }
        }
        write_json('users.json', $users);

        if ($game_id) {
            $games = read_json('games.json');
            if (isset($games[$game_id])) {
                // If game is 'lobby', remove player entirely
                if ($games[$game_id]['state'] === 'lobby') {
                    unset($games[$game_id]['players'][$my_id]);
                    // If no players left, delete game?
                    if (empty($games[$game_id]['players'])) {
                        unset($games[$game_id]);
                    }
                } else {
                    // Game in progress. Mark as 'left'.
                    // Or remove them? If we remove, score list might break.
                    // Mark as 'left' is safer.
                    if (isset($games[$game_id]['players'][$my_id])) {
                        $games[$game_id]['players'][$my_id]['status'] = 'left';
                        $games[$game_id]['players'][$my_id]['name'] .= ' (Abandon)';
                    }
                    
                    // Check if only 1 player remains active
                    $active_count = 0;
                    $last_active = null;
                    foreach($games[$game_id]['players'] as $pid => $p) {
                        if ($p['status'] !== 'left') {
                            $active_count++;
                            $last_active = $pid; // ID
                        }
                    }
                    
                    if ($active_count <= 1 && count($games[$game_id]['players']) > 1) {
                        // Game Over. Remaining player wins.
                        $games[$game_id]['state'] = 'finished';
                        // Maybe give bonus points? Na.
                    }
                     if ($active_count == 0) {
                        unset($games[$game_id]);
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

        // Find which game I am in
        $users = read_json('users.json');
        $my_user = null;
        foreach($users as $u) {
            if ($u['id'] == $my_id) {
                $my_user = $u;
                break;
            }
        }

        if (!$my_user || !$my_user['game_id']) {
            echo json_encode(['state' => 'idle']); // Not in game
            exit;
        }

        $game_id = $my_user['game_id'];
        $games = read_json('games.json');
        $game = $games[$game_id] ?? null;

        if (!$game) {
            // Game deleted (e.g. host left or finished)
            echo json_encode(['state' => 'ended']);
            exit;
        }

        // Return players info for Lobby UI
        if ($game['state'] === 'lobby') {
             echo json_encode([
                 'state' => 'lobby', 
                 'game' => $game,
                 'is_host' => ($game['host_id'] === $my_id)
             ]);
             exit;
        }
        
        // Timer Logic on Server Side (Passive)
        if ($game['state'] === 'playing') {
             $elapsed = time() - $game['timer_start'];
             $remaining = max(0, $game['round_duration'] - $elapsed);
             
             if ($remaining <= 0) {
                 // Round over, transition or auto-calculate?
                 // For this simple version, frontend will send 'submit_round' when timer ends, 
                 // but backend should also enforce. 
                 // Let's rely on frontend trigger for simplicity in "Bachelor" level code, 
                 // or make next poll switch state.
                 // We will switch to 'evaluating' state.
                 $game['state'] = 'evaluating';
                 $games[$game_id] = $game;
                 write_json('games.json', $games);
             }
             $game['time_remaining'] = $remaining;
        }

        echo json_encode(['success' => true, 'game' => $game]);
        break;

    case 'start_round':
        $game_id = $_POST['game_id'];
        $games = read_json('games.json');
        
        if (!isset($games[$game_id])) exit;
        
        // Pick letter A-Z
        $alphabet = range('A', 'Z');
        // Simple logic: go in order or random? User: "lettres de l'alphabet ABCD...Z"
        // Implies sequence.
        $round_idx = $games[$game_id]['current_round']; 
        // 0 -> A, 1 -> B
        
        // If > Z, just loop or random? "jusqu'a ce que ca soit 10" -> stops after 10.
        // So we just pick alphabet[$round_idx]
        
        if ($round_idx >= 10) {
             $games[$game_id]['state'] = 'finished';
             write_json('games.json', $games);
             echo json_encode(['finished' => true]);
             exit;
        }
        
        $games[$game_id]['current_letter'] = $alphabet[$round_idx];
        $games[$game_id]['state'] = 'playing';
        $games[$game_id]['timer_start'] = time();
        
        write_json('games.json', $games);
        echo json_encode(['success' => true]);
        break;

    case 'submit_answers':
        // Frontend sends answers when timer ends or user clicks submit
        $game_id = $_POST['game_id'];
        $answers = $_POST['answers'] ?? []; // ['pays' => 'France', ...]
        $my_id = $_SESSION['user_id'];
        
        $games = read_json('games.json');
        if (!isset($games[$game_id])) exit;
        
        $letter = $games[$game_id]['current_letter']; // e.g. 'A'
        $score_round = 0;
        
        foreach ($answers as $cat => $val) {
            $raw_val = trim($val);
            if (empty($raw_val)) continue;

            $normalized_val = normalize_string($raw_val);
            $target_letter = normalize_string($letter);

            // Validation Logic:
            // 1. Check if input starts with correct letter (primary rule)
            // BUT: If user makes a mistake like "Kamion" for "C", we might accept it if fuzzy match says so?
            // User requested tolerance.
            // Let's try Dictionary Match first.

            $is_valid = false;
            $corrected_word = null;

            if (isset($dictionary[$cat])) {
                foreach ($dictionary[$cat] as $word) {
                    $norm_word = normalize_string($word);
                    // Check if DICTIONARY word starts with target letter (it should, if the dictionary is good, but we must filter)
                    if (substr($norm_word, 0, 1) !== $target_letter) continue;

                    // Exact match?
                    if ($norm_word === $normalized_val) {
                        $is_valid = true;
                        $corrected_word = $word;
                        break;
                    }

                    // Fuzzy match? (Levenshtein)
                    // Allow 1 error for short words (<5), 2 for longer
                    $limit = (strlen($norm_word) < 5) ? 1 : 2;
                    if (levenshtein($normalized_val, $norm_word) <= $limit) {
                        $is_valid = true;
                        $corrected_word = $word; // "System understands" -> Corrects it
                        break; 
                    }
                }
            }

            // If found in dictionary (fuzzy or exact), it is VALID.
            if ($is_valid) {
                $score_round += 0.5;
                // Store corrected word? (Not using it for display yet, but good for future)
            } else {
                // Fallback: If not in dictionary (or dictionary empty), check first letter ONLY.
                // This covers valid words not in our small list.
                // However, we must ensure we don't reject "Kamion" if it was strict. 
                // Wait, if it wasn't found in dictionary, "Kamion" (starts with K) vs Letter C.
                // normalized_val starts with 'k'. target is 'c'.
                // If we rely on start letter, it FAILS. This is correct behavior for unknown words.
                
                // But "Corail" (starts with C) -> Not in dictionary -> Fallback Checks Start Letter -> PASS.
                // So unknown valid words pass.
                // Known words with typos pass via fuzzy.
                // Completely wrong start letter fails (unless fuzzy match found).

                if (substr($normalized_val, 0, 1) === $target_letter) {
                    $score_round += 0.5;
                }
            }
        }
        
        // Update player score
        $games[$game_id]['players'][$my_id]['score'] += $score_round;
        $games[$game_id]['players'][$my_id]['status'] = 'submitted';
        
        // Check if all players submitted
        $all_submitted = true;
        foreach ($games[$game_id]['players'] as $pid => $pdata) {
            if ($pdata['status'] !== 'submitted') $all_submitted = false;
        }
        
        if ($all_submitted) {
            // Advance round
            $games[$game_id]['current_round']++;
            // Reset player status
            foreach($games[$game_id]['players'] as &$p) {
                $p['status'] = 'ready';
            }

            if ($games[$game_id]['current_round'] >= 10) {
                $games[$game_id]['state'] = 'finished';
            } else {
                $games[$game_id]['state'] = 'round_transition'; 
                $games[$game_id]['timer_start'] = time(); // use timer for transition
            }
        }
        
        write_json('games.json', $games);
        echo json_encode(['success' => true]);
        break;
        
    case 'transition_check':
        // Called during round_transition to see if we should start next round
        // Simulating a Cron/Server Loop via player interaction
         $game_id = $_POST['game_id'];
         $games = read_json('games.json');
         if (!isset($games[$game_id])) exit;
         
         if ($games[$game_id]['state'] === 'round_transition') {
             if (time() - $games[$game_id]['timer_start'] > 5) {
                 // Start next
                 $alphabet = range('A', 'Z');
                 $idx = $games[$game_id]['current_round'];
                  if ($idx >= 10) {
                     $games[$game_id]['state'] = 'finished';
                  } else {
                    $games[$game_id]['current_letter'] = $alphabet[$idx];
                    $games[$game_id]['state'] = 'playing';
                    $games[$game_id]['timer_start'] = time();
                  }
                  write_json('games.json', $games);
             }
         }
         echo json_encode(['success' => true]);
         break;
}
?>
