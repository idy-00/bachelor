<?php
session_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Le Petit Bac - Édition Bachelor</title>
    <link rel="stylesheet" href="style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script> <!-- User requested 'supprime tout' so starting fresh, jQuery helps with quick AJAX -->
</head>
<body>
    <div class="app-container">
        
        <header class="main-header">
            <h1 class="gradient-text">Bachelor</h1>
            <p class="subtitle">Jouez avec vos amis en temps réel</p>
        </header>

        <!-- Login Section -->
        <section id="login-section" class="active-section">
            <div class="glass-card">
                <h1>Le Petit Bac 🎓</h1>
                <p>Rejoignez la partie en un clic.</p>
                <form id="login-form">
                    <input type="text" id="username" placeholder="Votre pseudonyme" required autocomplete="off">
                    <button type="submit" class="btn-primary">Entrer dans l'arène</button>
                </form>
            </div>
        </section>

        <!-- Lobby Section -->
        <section id="lobby-section" class="hidden-section">
            <header>
                <h2>Bienvenue, <span id="current-user-name"></span></h2>
                <div class="header-actions">
                    <span class="status-badge online">En ligne</span>
                </div>
            </header>
            
            <div class="lobby-grid">
                <div class="glass-card users-list">
                    <h3>Joueurs disponibles</h3>
                    <ul id="users-online">
                        <!-- User list populated via JS -->
                        <li class="empty-state">Recherche de joueurs...</li>
                    </ul>
                </div>

                <div class="glass-card invitations-panel">
                    <h3>Invitations</h3>
                    <div id="invitations-list">
                        <!-- Invitations appear here -->
                        <p class="empty-text">Aucune invitation pour le moment.</p>
                    </div>
                </div>
                
                <!-- My Game Lobby (Waiting Room) -->
                <div class="glass-card game-setup" id="my-game-lobby">
                     <h3>Ma Partie (Salle d'attente)</h3>
                     <div id="lobby-players-list" class="tags-container">
                         <!-- Players in my lobby -->
                         <span class="tag">Moi</span>
                     </div>
                     
                     <div id="host-controls" class="setup-controls hidden-section">
                        <label>Nombre de tours : <span id="rounds-display">10</span></label>
                        <input type="range" min="3" max="26" value="10" id="rounds-input">
                        <button id="start-game-btn" class="btn-primary full-width">LANCER LA PARTIE</button>
                     </div>
                     <div id="guest-controls" class="hidden-section">
                        <p class="hint">En attente de l'hôte pour commencer...</p>
                     </div>
                     
                     <button id="leave-lobby-btn" class="btn-secondary full-width" style="margin-top:10px;">Quitter le salon</button>
                </div>
            </div>
        </section>

        <!-- Game Section -->
        <section id="game-section" class="hidden-section">
            <div class="game-header-bar">
                <div class="timer-box">
                    <svg class="timer-circle" viewBox="0 0 36 36">
                        <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                        <path class="circle" stroke-dasharray="100, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" />
                    </svg>
                    <span id="time-left">60</span>s
                </div>
                <div class="letter-display">
                    Lettre actuelle
                    <span id="current-letter">?</span>
                </div>
                <div class="score-display">
                    Score: <span id="current-score">0</span>
                </div>
            </div>

            <div class="game-board glass-card">
                <form id="game-form">
                    <div class="input-group">
                        <label>Pays</label>
                        <input type="text" name="pays" data-category="pays" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Ville</label>
                        <input type="text" name="ville" data-category="ville" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Métier</label>
                        <input type="text" name="metier" data-category="metier" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Fruit / Légume</label>
                        <input type="text" name="fruit_legume" data-category="fruit_legume" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Objet</label>
                        <input type="text" name="objet" data-category="objet" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Animal</label>
                        <input type="text" name="animal" data-category="animal" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Prénom</label>
                        <input type="text" name="prenom" data-category="prenom" autocomplete="off">
                    </div>
                    <div class="input-group">
                        <label>Marque</label>
                        <input type="text" name="marque" data-category="marque" autocomplete="off">
                    </div>
                </form>
            </div>
            <div class="game-status-msg" id="game-status">En attente des autres joueurs...</div>
            <button id="quit-game-btn" class="btn-danger full-width" style="margin-top:20px;">ABANDONNER LA PARTIE</button>
        </section>
        
        <!-- Results Section -->
        <section id="results-section" class="hidden-section">
             <div class="glass-card celebration-card">
                 <h1>Fin de la partie ! 🏆</h1>
                 <div id="final-scoreboard">
                     <!-- Scores will be injected here -->
                 </div>
                 <button id="restart-btn" class="btn-secondary">Retour au Lobby</button>
             </div>
        </section>

    </div>

    <script src="script.js"></script>
</body>
</html>
