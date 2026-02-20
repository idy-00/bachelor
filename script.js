$(document).ready(function () {
    let currentUser = null;
    let pollInterval = null;
    let gameId = null;
    let gameState = 'idle';

    // *** RESTORE SESSION ***
    $.getJSON('api.php?action=whoami', function (res) {
        if (res.success && res.user) {
            currentUser = res.user;
            $('#current-user-name').text(currentUser.name);
            $('#username').val(currentUser.name); // Pre-fill just in case

            // If user was in game, rejoin? For now just go to lobby
            if (res.user.game_id) {
                gameId = res.user.game_id;
                gameState = 'in_game'; // Attempt to rejoin logic could be here
                // For simplicity in this fix, we just go to lobby first then poll determines game
                startGamePolling();
            } else {
                showSection('lobby-section');
                startLobbyPolling();
            }
        }
    });

    let currentRoundIndex = -1;
    let hasSubmittedCurrentRound = false;

    // *** UI UPDATES ***
    $('#rounds-input').on('input', function () {
        $('#rounds-display').text($(this).val());
    });

    // *** LOGIN ***
    $('#login-form').on('submit', function (e) {
        e.preventDefault();
        const username = $('#username').val();

        $.post('api.php?action=login', { username: username }, function (res) {
            if (res.success) {
                currentUser = res.user;
                $('#current-user-name').text(currentUser.name);
                showSection('lobby-section');
                startLobbyPolling();
            }
        }, 'json');
    });

    // *** LOBBY POLLING ***
    function startLobbyPolling() {
        if (pollInterval) clearInterval(pollInterval);

        // Poll every 2 seconds for users and invites
        pollInterval = setInterval(function () {
            if (gameState === 'in_game') return; // Stop lobby polling if in game

            // 1. List Users
            $.getJSON('api.php?action=list_users', function (res) {
                if (res.success) {
                    const list = $('#users-online');
                    list.empty();

                    // Filter out users already in my game? No, I might want to invite them to MY game even if they are 'online'.
                    // But if they are 'in_game' elsewhere, disable.

                    if (res.users.length === 0) {
                        list.append('<li class="empty-state">Aucun autre joueur disponible.</li>');
                    } else {
                        res.users.forEach(u => {
                            const statusLabel = u.status === 'in_game' || u.status === 'in_lobby' ? '(Occupé)' : '';
                            const btnDisabled = (u.status === 'in_game' || u.status === 'in_lobby') ? 'disabled' : '';
                            list.append(`
                                <li>
                                    <span class="name">${u.name} ${statusLabel}</span>
                                    <button class="btn-sm btn-secondary invite-btn" data-id="${u.id}" ${btnDisabled}>Inviter</button>
                                </li>
                            `);
                        });
                    }

                    // CHECK IF I AM IN A GAME (LOBBY OR PLAYING)
                    if (res.me && res.me.game_id) {
                        gameId = res.me.game_id;
                        // Determine if it is lobby or playing by asking game_poll
                        // Switch to Game Polling immediately which handles both Lobby and Playing UI
                        clearInterval(pollInterval);
                        startGamePolling();
                    }
                }
            });

            // 2. Check Invites (Only if not in game)
            $.getJSON('api.php?action=get_invites', function (res) {
                if (res.success) {
                    const invList = $('#invitations-list');
                    if (res.invites && res.invites.length > 0) {
                        invList.empty();
                        res.invites.forEach(inv => {
                            invList.append(`
                                <div class="invitation-item">
                                    <span>Invité par <strong>${inv.from_name}</strong></span>
                                    <div>
                                        <button class="btn-sm btn-primary accept-btn" data-id="${inv.id}">O</button>
                                        <button class="btn-sm btn-secondary reject-btn" data-id="${inv.id}">N</button>
                                    </div>
                                </div>
                            `);
                        });
                    } else {
                        invList.html('<p class="empty-text">Aucune invitation pour le moment.</p>');
                    }
                }
            });

        }, 2000);
    }

    // *** INVITE ACTIONS ***
    $(document).on('click', '.invite-btn', function () {
        const targetId = $(this).data('id');
        $.post('api.php?action=invite', { target_id: targetId }, function () {
            // Visual feedback
            const btn = $(`.invite-btn[data-id="${targetId}"]`);
            btn.text('Envoyé').prop('disabled', true);
        });
    });

    $(document).on('click', '.accept-btn', function () {
        const invId = $(this).data('id');
        $.post('api.php?action=respond_invite', { invite_id: invId, response: 'accepted' }, function () {
            // Wait for poll to pick up game_id
            $('#invitations-list').html('<p>Invitation acceptée. Rejoindre le salon...</p>');
        });
    });

    // Start Game (Host only)
    $('#start-game-btn').click(function () {
        const rounds = $('#rounds-input').val();
        $.post('api.php?action=start_game_host', { rounds: rounds }, function (res) {
            // Game state will change to 'playing', poll will catch it
        });
    });

    // Leave Lobby
    $('#leave-lobby-btn').click(function () {
        $.post('api.php?action=leave_game', function () {
            // Return to main lobby view
            $('#my-game-lobby').addClass('hidden-section'); // Hide waiting room
            // Restart standard poll
            gameId = null;
            startLobbyPolling();
        });
    });

    // Quit Game
    $('#quit-game-btn').click(function () {
        if (confirm('Voulez-vous vraiment abandonner ?')) {
            $.post('api.php?action=leave_game', function () {
                location.reload(); // Reload to reset state fully
            });
        }
    });

    // *** GAME LOGIC & WAITING ROOM ***
    function startGamePolling() {
        // Don't show game section immediately. We might be in Lobby OR in Game.

        const gameInterval = setInterval(function () {
            $.getJSON('api.php?action=game_poll', function (res) {
                // If api returns ended or idle, we should exit back to main lobby
                if (res.state === 'ended' || res.state === 'idle') {
                    clearInterval(gameInterval);
                    alert("La partie est terminée ou a été annulée.");
                    location.reload();
                    return;
                }

                // *** LOBBY STATE ***
                if (res.state === 'lobby') {
                    showSection('lobby-section'); // Stay in lobby section
                    $('#my-game-lobby').removeClass('hidden-section');

                    // Render Players in "My Party"
                    const playersDiv = $('#lobby-players-list');
                    playersDiv.empty();
                    const players = res.game.players;
                    let count = 0;

                    Object.values(players).forEach(p => {
                        playersDiv.append(`<span class="tag">${p.name}</span>`);
                        count++;
                    });

                    // Host Controls
                    if (res.is_host) {
                        $('#host-controls').removeClass('hidden-section');
                        $('#guest-controls').addClass('hidden-section');
                        $('#start-game-btn').text(`LANCER LA PARTIE (${count} Joueurs)`);
                    } else {
                        $('#host-controls').addClass('hidden-section');
                        $('#guest-controls').removeClass('hidden-section');
                    }

                    return; // Stop processing 'playing' logic
                }

                // *** PLAYING STATE ***
                showSection('game-section'); // Switch to game UI
                const game = res.game;

                // Detect Round Change to reset submission flag
                if (game.current_round !== currentRoundIndex) {
                    currentRoundIndex = game.current_round;
                    hasSubmittedCurrentRound = false;
                    // Ensure inputs are clear for the new round
                    $('#game-form input').val('');
                }

                // Update UI based on game State
                if (game.state === 'playing') {
                    $('#game-status').text('À vous de jouer !');
                    $('#current-letter').text(game.current_letter);
                    $('#rounds-display-game').text(game.current_round + 1 + '/' + game.total_rounds);
                    $('#time-left').text(game.time_remaining);

                    // Simple progress bar for timer
                    const total = game.round_duration;
                    const dashoffset = 100 - ((game.time_remaining / total) * 100);
                    $('.timer-circle .circle').css('stroke-dashoffset', dashoffset);

                    // Sync Score
                    if (game.players[currentUser.id]) {
                        const myScore = game.players[currentUser.id].score;
                        $('#current-score').text(myScore);
                    }

                    // If time is 0 locally, submit form (backup safety)
                    if (game.time_remaining <= 0 && !hasSubmittedCurrentRound) {
                        submitRound();
                    }
                } else if (game.state === 'evaluating') {
                    // Server says time is up. If we haven't submitted, forced submit now.
                    if (!hasSubmittedCurrentRound) {
                        submitRound();
                    }

                    $('#game-status').text('Calcul des points / Tour suivant...');

                    // Trigger transition check from client (any client)
                    $.post('api.php?action=transition_check', { game_id: game.id });
                } else if (game.state === 'round_transition') {
                    $('#game-status').text('Tour terminé. Préparez-vous...');
                    // Also poll transition check
                    $.post('api.php?action=transition_check', { game_id: game.id });

                } else if (game.state === 'finished') {
                    clearInterval(gameInterval);
                    showResults(game);
                }
            });
        }, 1000);
    }

    function submitRound() {
        if (hasSubmittedCurrentRound) return;
        hasSubmittedCurrentRound = true;

        // Collect data
        const data = {
            pays: $('input[name="pays"]').val(),
            ville: $('input[name="ville"]').val(),
            metier: $('input[name="metier"]').val(),
            fruit_legume: $('input[name="fruit_legume"]').val(),
            objet: $('input[name="objet"]').val(),
            animal: $('input[name="animal"]').val(),
            prenom: $('input[name="prenom"]').val(),
            marque: $('input[name="marque"]').val()
        };

        $.post('api.php?action=submit_answers', {
            game_id: gameId,
            answers: data
        }, function () {
            $('#game-status').text('Réponses envoyées ! Attente des autres...');
            // Clear inputs immediately
            $('#game-form input').val('');
        });
    }

    // Allow manual submit with Enter? Maybe risky. Let's auto-submit on timeout mostly.
    // Or add a button? User didn't ask for button, but "1 minute par lettre". 
    // Usually auto.

    function showResults(game) {
        showSection('results-section');
        const board = $('#final-scoreboard');
        board.empty();

        // Sort players by score
        const players = Object.values(game.players).sort((a, b) => b.score - a.score);

        // We need names, but game state only has IDs usually (optimized).
        // Actually, we invited by ID. 
        // Let's rely on cached name if possible or just display score.
        // For 'Bachelor' level, we can assume we know names or fetch them.
        // For now, let's just display "Joueur X" or match with known user list if possible.
        // Or better: store names in game object in api create_game. 
        // (API fix needed? I'll assume I can just list scores)

        players.forEach((p, index) => {
            const isWinner = index === 0;
            board.append(`
                <div class="result-row ${isWinner ? 'winner' : ''}">
                    <span class="rank">#${index + 1}</span>
                    <span class="score">${p.score} pts</span>
                     ${isWinner ? '👑' : ''}
                </div>
            `);
        });

        $('#restart-btn').off('click').click(function () {
            // Explicitly leave the game to clean up server state before reloading
            $.post('api.php?action=leave_game', function () {
                location.reload();
            });
        });
    }

    // Helper
    function showSection(id) {
        $('section').removeClass('active-section').addClass('hidden-section').hide();
        $('#' + id).removeClass('hidden-section').addClass('active-section').css('display', 'flex');
    }
});
