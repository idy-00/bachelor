$(document).ready(function () {
    let currentUser = null;
    let pollInterval = null;
    let gameInterval = null;
    let gameId = null;
    let currentRoundIndex = -1;
    let hasSubmittedCurrentRound = false;

    function escapeHtml(str) {
        return $('<div>').text(String(str)).html();
    }

    // *** RESTORE SESSION ***
    $.getJSON('api.php?action=whoami', function (res) {
        if (res.success && res.user) {
            currentUser = res.user;
            $('#current-user-name').text(escapeHtml(currentUser.name));

            if (res.user.game_id) {
                gameId = res.user.game_id;
                startGamePolling();
            } else {
                showSection('lobby-section');
                startLobbyPolling();
            }
        }
    });

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
                $('#current-user-name').text(escapeHtml(currentUser.name));
                showSection('lobby-section');
                startLobbyPolling();
            }
        }, 'json');
    });

    // *** LOBBY POLLING ***
    function startLobbyPolling() {
        stopAllPolling();

        pollInterval = setInterval(function () {
            $.getJSON('api.php?action=list_users', function (res) {
                if (!res.success) return;

                const list = $('#users-online');
                list.empty();

                if (res.users.length === 0) {
                    list.append('<li class="empty-state">Aucun autre joueur disponible.</li>');
                } else {
                    res.users.forEach(u => {
                        const occupied   = u.status === 'in_game' || u.status === 'in_lobby';
                        const statusLabel = occupied ? ' <em>(Occupé)</em>' : '';
                        const btnDisabled = occupied ? 'disabled' : '';
                        list.append(`
                            <li>
                                <span class="name">${escapeHtml(u.name)}${statusLabel}</span>
                                <button class="btn-sm btn-secondary invite-btn" data-id="${escapeHtml(u.id)}" ${btnDisabled}>Inviter</button>
                            </li>
                        `);
                    });
                }

                if (res.me && res.me.game_id) {
                    gameId = res.me.game_id;
                    stopAllPolling();
                    startGamePolling();
                }
            });

            $.getJSON('api.php?action=get_invites', function (res) {
                if (!res.success) return;
                const invList = $('#invitations-list');

                if (res.invites && res.invites.length > 0) {
                    invList.empty();
                    res.invites.forEach(inv => {
                        invList.append(`
                            <div class="invitation-item">
                                <span>Invité par <strong>${escapeHtml(inv.from_name)}</strong></span>
                                <div>
                                    <button class="btn-sm btn-primary accept-btn" data-id="${escapeHtml(inv.id)}">Accepter</button>
                                    <button class="btn-sm btn-secondary reject-btn" data-id="${escapeHtml(inv.id)}">Refuser</button>
                                </div>
                            </div>
                        `);
                    });
                } else {
                    invList.html('<p class="empty-text">Aucune invitation pour le moment.</p>');
                }
            });

        }, 2000);
    }

    // *** INVITE ACTIONS ***
    $(document).on('click', '.invite-btn', function () {
        const targetId = $(this).data('id');
        $.post('api.php?action=invite', { target_id: targetId }, function () {
            $(`.invite-btn[data-id="${targetId}"]`).text('Envoyé').prop('disabled', true);
        });
    });

    $(document).on('click', '.accept-btn', function () {
        const invId = $(this).data('id');
        $.post('api.php?action=respond_invite', { invite_id: invId, response: 'accepted' }, function () {
            $('#invitations-list').html('<p class="empty-text">Invitation acceptée. Rejoindre le salon...</p>');
        });
    });

    $(document).on('click', '.reject-btn', function () {
        const invId = $(this).data('id');
        $(this).closest('.invitation-item').remove();
        $.post('api.php?action=respond_invite', { invite_id: invId, response: 'declined' });
    });

    // *** START / LEAVE GAME ***
    $('#start-game-btn').click(function () {
        const rounds = $('#rounds-input').val();
        $.post('api.php?action=start_game_host', { rounds: rounds });
    });

    $('#leave-lobby-btn').click(function () {
        $.post('api.php?action=leave_game', function () {
            gameId = null;
            $('#my-game-lobby').addClass('hidden-section');
            startLobbyPolling();
        });
    });

    $('#quit-game-btn').click(function () {
        if (confirm('Voulez-vous vraiment abandonner ?')) {
            $.post('api.php?action=leave_game', function () {
                location.reload();
            });
        }
    });

    // *** GAME POLLING ***
    function startGamePolling() {
        stopAllPolling();

        gameInterval = setInterval(function () {
            $.getJSON('api.php?action=game_poll', function (res) {
                if (res.state === 'ended' || res.state === 'idle') {
                    stopAllPolling();
                    alert('La partie est terminée ou a été annulée.');
                    location.reload();
                    return;
                }

                // LOBBY STATE
                if (res.state === 'lobby') {
                    showSection('lobby-section');
                    $('#my-game-lobby').removeClass('hidden-section');

                    const playersDiv = $('#lobby-players-list');
                    playersDiv.empty();
                    const players = res.game.players;
                    let count = 0;

                    Object.values(players).forEach(p => {
                        playersDiv.append(`<span class="tag">${escapeHtml(p.name)}</span>`);
                        count++;
                    });

                    if (res.is_host) {
                        $('#host-controls').removeClass('hidden-section');
                        $('#guest-controls').addClass('hidden-section');
                        $('#start-game-btn').text(`LANCER LA PARTIE (${count} joueur${count > 1 ? 's' : ''})`);
                    } else {
                        $('#host-controls').addClass('hidden-section');
                        $('#guest-controls').removeClass('hidden-section');
                    }
                    return;
                }

                // PLAYING STATE
                showSection('game-section');
                const game = res.game;

                if (game.current_round !== currentRoundIndex) {
                    currentRoundIndex = game.current_round;
                    hasSubmittedCurrentRound = false;
                    $('#game-form input').val('');
                    // New round — scroll to top of game header
                    scrollToGameHeader();
                }

                if (game.state === 'playing') {
                    $('#game-status').text('À vous de jouer !');
                    $('#current-letter').text(game.current_letter);
                    $('#round-counter').text((game.current_round + 1) + ' / ' + game.total_rounds);
                    $('#time-left').text(game.time_remaining);

                    const pct       = game.time_remaining / game.round_duration;
                    const dashoffset = 100 - (pct * 100);
                    $('.timer-circle .circle').css('stroke-dashoffset', dashoffset);

                    // Turn the timer red in the last 10 seconds
                    if (game.time_remaining <= 10) {
                        $('.timer-circle .circle').css('stroke', 'var(--primary)');
                        $('#time-left').addClass('time-urgent');
                    } else {
                        $('.timer-circle .circle').css('stroke', 'var(--accent)');
                        $('#time-left').removeClass('time-urgent');
                    }

                    if (game.players && game.players[currentUser.id]) {
                        $('#current-score').text(game.players[currentUser.id].score);
                    }

                    if (game.time_remaining <= 0 && !hasSubmittedCurrentRound) {
                        scrollToGameHeader();
                        submitRound(game);
                    }

                } else if (game.state === 'evaluating') {
                    if (!hasSubmittedCurrentRound) {
                        scrollToGameHeader();
                        submitRound(game);
                    }
                    $('#game-status').text('Calcul des points… tour suivant bientôt !');
                    $.post('api.php?action=transition_check', { game_id: game.id });

                } else if (game.state === 'round_transition') {
                    $('#game-status').text('Tour terminé. Préparez-vous…');
                    $.post('api.php?action=transition_check', { game_id: game.id });

                } else if (game.state === 'finished') {
                    stopAllPolling();
                    showResults(game);
                }
            });
        }, 1000);
    }

    function scrollToGameHeader() {
        $('html, body').animate({ scrollTop: $('#game-section').offset().top - 20 }, 300);
    }

    function submitRound(game) {
        if (hasSubmittedCurrentRound) return;
        hasSubmittedCurrentRound = true;

        const data = {
            pays:        $('input[name="pays"]').val(),
            ville:       $('input[name="ville"]').val(),
            metier:      $('input[name="metier"]').val(),
            fruit_legume:$('input[name="fruit_legume"]').val(),
            objet:       $('input[name="objet"]').val(),
            animal:      $('input[name="animal"]').val(),
            prenom:      $('input[name="prenom"]').val(),
            marque:      $('input[name="marque"]').val()
        };

        $.post('api.php?action=submit_answers', {
            game_id: gameId || game.id,
            answers: data
        }, function () {
            $('#game-status').text('Réponses envoyées ! En attente des autres joueurs…');
            $('#game-form input').val('').prop('disabled', true);
        });
    }

    function showResults(game) {
        showSection('results-section');
        const board = $('#final-scoreboard');
        board.empty();

        const players = Object.values(game.players).sort((a, b) => b.score - a.score);

        players.forEach((p, index) => {
            const isWinner = index === 0;
            const crown    = isWinner ? '<span class="crown">👑</span>' : '';
            board.append(`
                <div class="result-row ${isWinner ? 'winner' : ''}">
                    <span class="rank">#${index + 1}</span>
                    <span class="player-name">${escapeHtml(p.name)}</span>
                    <span class="score">${p.score} pts</span>
                    ${crown}
                </div>
            `);
        });

        $('#restart-btn').off('click').click(function () {
            $.post('api.php?action=leave_game', function () {
                location.reload();
            });
        });
    }

    function stopAllPolling() {
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
        if (gameInterval) { clearInterval(gameInterval); gameInterval = null; }
    }

    function showSection(id) {
        $('section').removeClass('active-section').addClass('hidden-section').hide();
        $('#' + id).removeClass('hidden-section').addClass('active-section').css('display', 'flex');
    }
});
