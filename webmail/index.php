<?php
require_once 'check_auth.php';
require_once __DIR__ . '/lib/tema.php';
// Colore dell'interfaccia scelto dall'utente (o quello predefinito): impostato
// già qui sull'elemento <html>, così la pagina non "lampeggia" col colore base
$temaPagina = temaUtente((int)($_SESSION['user_id'] ?? 0));
$archivioCollegato = env('ARCHIVIO_URL', '') !== '';
$nomeUtente = (string)($_SESSION['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="it"<?php echo attributoTema($temaPagina); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📧 PiGreco Posta</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Lato:wght@300;400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="temi.css?v=1">
    <link rel="stylesheet" href="style.css?v=2.2">
    <link rel="stylesheet" href="archivio.css?v=2.1">
    <link rel="stylesheet" href="contatti.css?v=2">
    <link rel="stylesheet" href="filtri.css?v=1">
    <link rel="stylesheet" href="selezione.css?v=3">
    <link rel="stylesheet" href="cartelle.css?v=1">
    <link rel="stylesheet" href="ricerca.css?v=1">
    <link rel="stylesheet" href="telefono.css?v=1">
    <link rel="stylesheet" href="fontawesome/css/all.min.css">
    <script>
        // Id dell'account email selezionato (valorizzato da viewNewEmail; altrimenti
        // vale il valore della select #userSelect, letta come fonte di verità)
        let currentUser = '';
        let is_admin = <?php echo isAdmin() ? 'true' : 'false'; ?>;
        // PiGreco Archivio collegato (container docker "webmail"): vuoto se la
        // webmail è installata da sola. Vedi archivio.js.
        const ARCHIVIO_URL = <?php echo json_encode(env('ARCHIVIO_URL', '')); ?>;
        // Colori disponibili nella finestra "Aspetto" (tema.js)
        const TEMI_WEBMAIL = <?php echo json_encode(temiDisponibili(), JSON_UNESCAPED_UNICODE); ?>;
        // Nomi delle cartelle mostrati nell'interfaccia
        const NOMI_CARTELLE = { inbox: 'Posta in arrivo', sent: 'Inviata', draft: 'Bozze', junk: 'Indesiderata', trash: 'Cestino' };
        // Altre cartelle dell'account ("imap:<nome>" → percorso), da get_folders.php
        let altreCartelle = {};
        // Account di cui sono mostrate le altre cartelle
        let altreCartelleAccount = null;
        function nomeCartella(folder) {
            return NOMI_CARTELLE[folder] || altreCartelle[folder] || 'Cartella';
        }

        // Gli endpoint rispondono 401 JSON quando la sessione è scaduta:
        // invece di mostrare un errore generico si torna alla pagina di login.
        (function() {
            const originalFetch = window.fetch;
            window.fetch = function(...args) {
                return originalFetch.apply(this, args).then(response => {
                    // Le chiamate all'API dell'archivio (/api/...) hanno una loro
                    // sessione: un 401 lì non riguarda il login della webmail
                    const url = String(args[0] instanceof Request ? args[0].url : args[0]);
                    const eApiArchivio = url.startsWith('/api/') || url.startsWith(window.location.origin + '/api/');
                    if (response.status === 401 && !eApiArchivio) {
                        window.location.href = 'login.php?timeout=1';
                    }
                    return response;
                });
            };
        })();
    </script>
    
</head>
<body>
    <!-- ==================== BARRA IN ALTO ==================== -->
    <header class="topbar">
        <button type="button" class="btn-top btn-menu-cartelle" onclick="toggleCartelle(event)" title="Cartelle e account">
            <i class="fas fa-bars"></i>
        </button>
        <div class="topbar-logo">
            <i class="fas fa-envelope-open-text"></i><span>PiGreco Posta</span>
        </div>

        <div class="search-container">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="searchInput" placeholder="Cerca nella posta…"
                   onkeypress="handleSearchKeyPress(event)">
            <button type="button" class="btn-search" onclick="openSearchDialog()" title="Opzioni di ricerca">
                <i class="fas fa-sliders-h"></i>
            </button>
        </div>

        <div class="topbar-azioni">
            <button id="newEmailsBtn" class="btn-top btn-new-emails" title="Email non lette di tutti gli account">
                <i class="fas fa-bell"></i>
                <span id="newEmailsBadge" class="badge-counter">0</span>
            </button>
            <?php if ($archivioCollegato): ?>
            <button id="archivioBtn" class="btn-top btn-archivio solo-largo" title="Apri PiGreco Archivio" onclick="apriArchivio()">
                <i class="fas fa-archive"></i><span class="testo">Archivio</span>
            </button>
            <?php endif; ?>
            <button type="button" class="btn-top solo-largo" title="<?php echo isAdmin() ? 'Impostazioni' : 'Aspetto'; ?>"
                    onclick="<?php echo isAdmin() ? 'showSettings()' : 'apriAspetto()'; ?>">
                <i class="fas fa-cog"></i>
            </button>

            <!-- Menu utente (id settingsMenu / classe settings-btn usati da toggleSettingsDropdown) -->
            <div class="settings-dropdown">
                <button type="button" class="btn-top settings-btn" onclick="toggleSettingsDropdown(event)" title="<?php echo htmlspecialchars($nomeUtente); ?>">
                    <span class="avatar-utente"><?php echo htmlspecialchars(mb_strtoupper(mb_substr($nomeUtente, 0, 1))); ?></span>
                    <i class="fas fa-caret-down"></i>
                </button>
                <div class="settings-menu" id="settingsMenu">
                    <div class="settings-menu-utente">
                        <strong><?php echo htmlspecialchars($nomeUtente); ?></strong>
                        <small><?php echo isAdmin() ? 'amministratore' : 'operatore'; ?></small>
                    </div>
                    <?php if ($archivioCollegato): ?>
                    <div class="settings-item solo-stretto" onclick="document.getElementById('settingsMenu').classList.remove('show'); apriArchivio();">
                        <i class="fas fa-archive"></i> PiGreco Archivio
                    </div>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                    <div class="settings-item" onclick="showSettings()">
                        <i class="fas fa-cog"></i> Impostazioni
                    </div>
                    <?php endif; ?>
                    <div class="settings-item" onclick="apriAspetto()">
                        <i class="fas fa-palette"></i> Aspetto
                    </div>
                    <?php if ($archivioCollegato): ?>
                    <div class="settings-item" onclick="showContatti()">
                        <i class="fas fa-address-book"></i> Contatti
                    </div>
                    <?php endif; ?>
                    <div class="settings-item" onclick="FiltriEmail.apri()">
                        <i class="fas fa-filter"></i> Filtri
                    </div>
                    <div class="settings-item settings-item-esci" id="logoutBtn">
                        <i class="fas fa-sign-out-alt"></i> Esci
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="app-container">
        <!-- ==================== CARTELLE ==================== -->
        <nav class="pannello-cartelle" id="pannelloCartelle">
            <button type="button" class="btn-nuova-email" onclick="composeEmail()" title="Nuova email">
                <i class="fas fa-pen"></i><span>Nuova email</span>
            </button>

            <div class="user-select-container" id="userSelectContainer">
                <label for="userSelect">Account</label>
                <select id="userSelect" onchange="loadEmails(); chiudiCartelle();">
                    <option value="">-- Seleziona un account --</option>
                </select>
            </div>

            <div class="folder-buttons">
                <button class="folder-btn active" data-folder="inbox" onclick="switchFolder('inbox')" title="Posta in arrivo">
                    <i class="fas fa-inbox"></i><span>Posta in arrivo</span>
                </button>
                <button class="folder-btn" data-folder="sent" onclick="switchFolder('sent')" title="Inviata">
                    <i class="fas fa-paper-plane"></i><span>Inviata</span>
                </button>
                <button class="folder-btn" data-folder="draft" onclick="switchFolder('draft')" title="Bozze">
                    <i class="far fa-file-alt"></i><span>Bozze</span>
                </button>
                <button class="folder-btn" data-folder="junk" onclick="switchFolder('junk')" title="Indesiderata">
                    <i class="fas fa-ban"></i><span>Indesiderata</span>
                </button>
                <button class="folder-btn" data-folder="trash" onclick="switchFolder('trash')" title="Cestino">
                    <i class="fas fa-trash-alt"></i><span>Cestino</span>
                </button>
            </div>

            <!-- Cartelle create dall'utente sul server (loadAltreCartelle) -->
            <div class="folder-buttons altre-cartelle" id="altreCartelle"></div>
        </nav>

        <!-- ==================== ELENCO EMAIL ==================== -->
        <section class="sidebar" id="sidebar">
            <div class="email-count">
                <span id="emailCount">0 email</span>
            </div>

            <div class="sidebar-content">
                <div id="loadingSidebar" class="loading-overlay" style="display:none;">
                    <div class="spinner"></div>
                    <p>Caricamento...</p>
                </div>

                <div id="emailList" class="email-list">
                    <!-- Lista email qui -->
                </div>
            </div>

            <!-- Paginazione -->
            <div class="pagination-controls">
                <button id="prevPageBtn" class="pagination-btn" onclick="goToPage(currentPage - 1)" disabled title="Pagina precedente">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <div class="pagination-info">
                    <span id="paginationInfo">Nessuna email</span>
                    <span class="pagination-pages">pag. <span id="currentPageDisplay">1</span>/<span id="totalPagesDisplay">1</span></span>
                </div>
                <select id="perPageSelect" onchange="changePerPage()" title="Email per pagina">
                    <option value="20">20</option>
                    <option value="50" selected>50</option>
                    <option value="100">100</option>
                    <option value="200">200</option>
                    <option value="300">300</option>
                    <option value="500">500</option>
                    <option value="0">Tutte</option>
                </select>
                <button id="nextPageBtn" class="pagination-btn" onclick="goToPage(currentPage + 1)" disabled title="Pagina successiva">
                    <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        </section>

        <!-- Trascinando si cambia la larghezza dell'elenco -->
        <div class="sidebar-resizer" id="resizer"></div>

        <!-- ==================== LETTURA ==================== -->
        <main class="main-content" id="mainContent">
            <div class="content-body">
                <div id="loadingDetail" class="loading-overlay" style="display:none;">
                    <div class="spinner-large"></div>
                    <p>Caricamento email...</p>
                </div>

                <div id="emailDetail" class="email-detail" style="display:none;">
                    <!-- Azioni -->
                    <div class="email-action-buttons">
                        <button type="button" class="action-btn btn-indietro" onclick="tornaAllElenco()" title="Torna all'elenco">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <button id="replyBtn" class="action-btn reply-btn" onclick="replyEmail()" title="Rispondi">
                            <i class="fas fa-reply"></i><span class="testo">Rispondi</span>
                        </button>
                        <button id="replyAllBtn" class="action-btn reply-btn" onclick="replyAllEmail()" title="Rispondi a tutti">
                            <i class="fas fa-reply-all"></i><span class="testo">Rispondi a tutti</span>
                        </button>
                        <button id="forwardBtn" class="action-btn forward-btn" onclick="forwardEmail()" title="Inoltra">
                            <i class="fas fa-share"></i><span class="testo">Inoltra</span>
                        </button>
                        <?php if ($archivioCollegato): ?>
                        <span class="separatore"></span>
                        <button class="action-btn archivia-btn" onclick="apriArchiviaEmail()" title="Archivia l'email e i suoi allegati in PiGreco Archivio">
                            <i class="fas fa-archive"></i><span class="testo">Archivia</span>
                        </button>
                        <?php endif; ?>
                        <span class="separatore"></span>
                        <button class="action-btn btn-unread" onclick="markAsUnread()" title="Segna come non letta">
                            <i class="far fa-envelope"></i>
                        </button>
                        <button class="action-btn save-btn" onclick="saveEmail()" title="Salva l'email sul computer">
                            <i class="fas fa-download"></i>
                        </button>
                        <button class="action-btn print-btn" onclick="printEmail()" title="Stampa">
                            <i class="fas fa-print"></i>
                        </button>
                        <button class="action-btn bozza-btn" onclick="BozzeEmail.apri()" title="Riapri la bozza per continuare a scriverla" style="display:none;">
                            <i class="fas fa-pen"></i><span class="testo">Modifica bozza</span>
                        </button>
                        <button class="action-btn importante-btn" onclick="SelezioneEmail.importanteAperta()" title="Segna come importante">
                            <i class="far fa-star"></i>
                        </button>
                        <button class="action-btn sposta-btn" onclick="SelezioneEmail.spostaAperta()" title="Sposta in un'altra cartella">
                            <i class="fas fa-folder-open"></i>
                        </button>
                        <button class="action-btn filtro-btn" onclick="FiltriEmail.daEmail()" title="Crea un filtro per le email di questo mittente">
                            <i class="fas fa-filter"></i>
                        </button>
                        <button class="action-btn delete-btn" onclick="deleteEmail()" title="Sposta nel Cestino">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                        <button class="action-btn restore-btn" onclick="restoreFromTrash()" title="Ripristina dal Cestino" style="display:none;">
                            <i class="fas fa-undo"></i><span class="testo">Ripristina</span>
                        </button>
                    </div>

                    <!-- Intestazione email -->
                    <div class="email-header-detail">
                        <h1 id="detailSubject" class="email-subject">Oggetto email</h1>
                        <div class="email-meta">
                            <div class="avatar-mittente" id="detailAvatar" aria-hidden="true">?</div>
                            <div class="email-meta-testo">
                                <div class="email-detail-field email-detail-da">
                                    <span id="detailFrom">Sconosciuto</span>
                                    <button type="button" id="aggiungiMittenteBtn" class="aggiungi-mittente-btn" hidden title="Aggiungi il mittente ai contatti"><i class="fas fa-user-plus"></i></button>
                                </div>
                                <div class="email-detail-field">
                                    <strong>A:</strong>
                                    <span id="detailTo">Sconosciuto</span>
                                </div>
                            </div>
                            <div class="email-detail-field email-detail-data">
                                <span id="detailDate">Data sconosciuta</span>
                            </div>
                        </div>
                        <!-- "Mittente non nei contatti: Aggiungi / No, grazie" (contatti.js) -->
                        <div id="bannerContatto" class="banner-contatto" hidden></div>
                    </div>

                    <!-- Corpo email -->
                    <div class="email-body-container">
                        <div class="email-body-tabs">
                            <button class="tab-btn active" onclick="switchTab('html')">
                                <i class="fas fa-file-code"></i> HTML
                            </button>
                            <button class="tab-btn" onclick="switchTab('text')">
                                <i class="fas fa-file-alt"></i> Testo
                            </button>
                        </div>
                        <div id="emailBodyContent" class="email-body-content">
                            <div id="htmlTab" class="tab-content active">
                                <!-- sandbox senza allow-scripts: l'HTML dell'email (non fidato) è
                                     isolato in un documento separato, non può eseguire script né un
                                     tag <base> può alterare gli URL relativi della pagina principale.
                                     allow-same-origin serve solo a leggere l'altezza del contenuto per
                                     ridimensionare l'iframe: sicuro in assenza di allow-scripts, perché
                                     non c'è alcun codice della email che possa sfruttarlo.
                                     I link si aprono in una nuova scheda (<base target="_blank"> in
                                     buildEmailIframeDoc): dentro l'iframe i siti esterni rifiutano di
                                     farsi mostrare ("connessione negata"). allow-popups-to-escape-sandbox
                                     fa sì che la pagina aperta funzioni normalmente, fuori dal sandbox. -->
                                <iframe id="detailBodyHtml" sandbox="allow-popups allow-popups-to-escape-sandbox allow-same-origin" referrerpolicy="no-referrer"></iframe>
                            </div>
                            <div id="textTab" class="tab-content">
                                <pre id="detailBodyText" class="email-text-body"></pre>
                            </div>
                        </div>
                    </div>

                    <!-- Allegati -->
                    <div id="attachmentsSection" class="attachments-section" style="display:none;">
                        <div class="attachments-header">
                            <h3><i class="fas fa-paperclip"></i> Allegati</h3>
                            <button id="downloadAllBtn" class="btn-download-all" onclick="downloadAllAttachments()" style="display:none;">
                                <i class="fas fa-file-archive"></i> Scarica tutti (.zip)
                            </button>
                        </div>
                        <div id="attachmentsList" class="attachments-grid"></div>
                    </div>
                </div>

                <div id="emptyState" class="empty-state" style="display:block;">
                    <i class="fas fa-envelope-open-text"></i>
                    <h2 id="contentTitle">Seleziona un account per visualizzare le email</h2>
                    <p>Scegli un'email dall'elenco per leggerla qui</p>
                </div>
            </div>
        </main>
    </div>
    <!-- Search Dialog Modal -->
    <div id="searchDialog" class="search-dialog-overlay" style="display:none;">
        <div class="search-dialog">
            <div class="search-dialog-header">
                <h3><i class="fas fa-search"></i> Ricerca Email</h3>
                <button class="btn-close-dialog" onclick="closeSearchDialog()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="search-dialog-body">
                <div class="search-form">
                    <div class="search-field">
                        <label for="searchQuery">
                            <i class="fas fa-keyboard"></i> Testo da cercare:
                        </label>
                        <input type="text" id="searchQuery" placeholder="Inserisci il testo da cercare..." 
                               onkeypress="handleSearchKeyPress(event)">
                    </div>
                    
                    <div class="search-field">
                        <label for="searchDove">
                            <i class="fas fa-folder"></i> Dove cercare:
                        </label>
                        <select id="searchDove">
                            <option value="qui">Nella cartella aperta</option>
                            <option value="tutte">In tutte le cartelle</option>
                        </select>
                    </div>

                    <div class="search-options">
                        <label class="search-option">
                            <input type="checkbox" id="searchEmail" checked>
                            <i class="fas fa-user"></i> Mittente e destinatari
                        </label>
                        <label class="search-option">
                            <input type="checkbox" id="searchSubject" checked>
                            <i class="fas fa-heading"></i> Oggetto
                        </label>
                        <label class="search-option">
                            <input type="checkbox" id="searchBody">
                            <i class="fas fa-align-left"></i> Testo dell'email
                        </label>
                        <label class="search-option">
                            <input type="checkbox" id="searchImportanti">
                            <i class="fas fa-star"></i> Solo email importanti
                        </label>
                    </div>
                    
                    <div class="search-hint">
                        <i class="fas fa-info-circle"></i>
                        <span>La ricerca guarda <strong>tutte</strong> le email della cartella (o di tutte le cartelle) sul server
                        di posta. Cercare nel testo delle email può richiedere qualche secondo.</span>
                    </div>
                </div>
                
                <div class="search-actions">
                    <button class="btn-search-execute" onclick="executeSearch()">
                        <i class="fas fa-search"></i> Cerca
                    </button>
                    <button class="btn-search-cancel" onclick="closeSearchDialog()">
                        <i class="fas fa-times"></i> Annulla
                    </button>
                </div>
                
                <div id="searchResults" class="search-results" style="display:none;">
                    <div class="search-results-header">
                        <h4>Risultati della ricerca (<span id="resultsCount">0</span>)</h4>
                        <button class="btn-clear-results" onclick="clearSearchResults()">
                            <i class="fas fa-trash"></i> Pulisci
                        </button>
                    </div>
                    <div id="resultsList" class="results-list"></div>
                </div>
            </div>
        </div>
    </div>
<!-- ==================== COMPOSE EMAIL MODAL ==================== -->
<div id="composeModal" class="compose-modal-overlay" style="display:none;">
    <div class="compose-modal compose-modal-scrivi">
        <div class="compose-modal-header" id="composeModalHeader" onclick="restoreComposeModal()">
            <h3 id="composeModalTitle"><i class="fas fa-edit"></i> Nuova email</h3>
            <div class="compose-modal-header-azioni">
                <button class="btn-minimize-compose" id="btnMinimizeCompose" onclick="event.stopPropagation(); toggleMinimizeCompose()" title="Riduci">
                    <i class="fas fa-minus"></i>
                </button>
                <button class="btn-close-compose" onclick="event.stopPropagation(); closeComposeModal()" title="Chiudi">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        
        <div class="compose-modal-body">
            <form id="composeForm" enctype="multipart/form-data">
                <!-- Intestazione: una riga per campo, come nei client di posta -->
                <div class="compose-campi">
                    <div class="form-row compose-campo">
                        <label>Da</label>
                        <span id="composeDa" class="compose-da"></span>
                    </div>
                    <div class="form-row compose-campo">
                        <label for="composeTo">A</label>
                        <input type="text" inputmode="email" spellcheck="false" id="composeTo" name="to" required placeholder="destinatario@esempio.it">
                    </div>
                    <div class="form-row compose-campo">
                        <label for="composeCc">Cc</label>
                        <input type="text" inputmode="email" spellcheck="false" id="composeCc" name="cc" placeholder="copia@esempio.it">
                    </div>
                    <div class="form-row compose-campo">
                        <label for="composeBcc" title="Copia nascosta">Ccn</label>
                        <input type="text" inputmode="email" spellcheck="false" id="composeBcc" name="bcc" placeholder="copia nascosta">
                    </div>
                    <div class="form-row compose-campo">
                        <label for="composeSubject">Oggetto</label>
                        <input type="text" id="composeSubject" name="subject" required placeholder="Oggetto del messaggio">
                    </div>
                </div>

                <!-- Formato: l'ordine Testo/HTML è usato da toggleFormat (first/last-child) -->
                <div class="compose-formato">
                    <span class="compose-formato-etichetta">Formato</span>
                    <div class="format-toggle">
                        <button type="button" class="format-btn " onclick="toggleFormat('text')">
                            <i class="fas fa-font"></i> Testo
                        </button>
                        <button type="button" class="format-btn active" onclick="toggleFormat('html')">
                            <i class="fas fa-code"></i> HTML
                        </button>
                    </div>
                </div>
                
                <!-- Corpo email - Editor HTML WYSIWYG -->
                <div class="form-group compose-corpo">
                    <div id="emailEditorContainer" class="email-editor-container">
                        <!-- L'editor sarà inizializzato qui da JavaScript -->
                    </div>
                </div>
                
                <div class="form-row attachments-section compose-allegati">
                    <label for="composeAttachments" class="compose-allega">
                        <i class="fas fa-paperclip"></i> Allega file
                    </label>
                    <input type="file" id="composeAttachments" name="attachments[]" multiple>
                    <div id="attachmentPreview" class="attachment-preview"></div>
                </div>
                
                <!-- ============ CAMPI NASCOSTI CRITICI ============ -->
                <input type="hidden" id="composeFromEmail" name="from_email">
                <input type="hidden" id="composeFromName" name="from_name">
                <input type="hidden" id="composeIsHtml" name="is_html" value="1">
                <!-- Account mittente (id) bloccato al momento dell'apertura del compose -->
                <input type="hidden" id="composeAccountId" name="account_id" value="">
                <!-- Bozza da sostituire al salvataggio o da cancellare dopo l'invio (bozze.js) -->
                <input type="hidden" id="composeBozzaUid" name="bozza_uid" value="">
                <!-- CAMPO OBBLIGATORIO PER INOLTRO ALLEGATI -->
                <input type="hidden" id="forwardOriginalEmail" name="forward_original_email" value="">
                <!-- ================================================= -->
                
                <!-- ============ WARNING PER INOLTRO ============ -->
                <div id="forwardWarning" class="forward-warning" style="display:none;">
                    <i class="fas fa-info-circle"></i>
                    <strong>Nota:</strong> Gli allegati dell'email originale saranno inoltrati automaticamente.
                    Compila solo il destinatario e invia.
                </div>
                <!-- ============================================== -->
                
                <div class="compose-modal-actions">
                    <button type="button" class="btnsend" onclick="sendEmail()">
                        <i class="fas fa-paper-plane"></i> Invia
                    </button>
                    <button type="button" class="btn-salva-bozza" onclick="BozzeEmail.salva()" title="Salva nella cartella Bozze">
                        <i class="fas fa-save"></i> Salva bozza
                    </button>
                    <span id="statoBozza" class="stato-bozza" aria-live="polite"></span>
                    <button type="button" class="btn-cancel" onclick="closeComposeModal()">
                        Annulla
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Gestione Identità -->
<?php if (isAdmin()): ?>
<div id="settingsModal" class="compose-modal-overlay" style="display:none;">
    <div class="compose-modal settings-modal" style="max-width: 1100px;">
        <div class="compose-modal-header">
            <h3><i class="fas fa-cog"></i> Impostazioni</h3>
            <button class="btn-close-compose" onclick="closeSettingsModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="settings-window-body">
            <!-- Sidebar menu impostazioni -->
            <div class="settings-sidebar">
                <div class="settings-sidebar-item active" data-settings-panel="mailaccounts" onclick="switchSettingsPanel('mailaccounts')">
                    <i class="fas fa-envelope-open-text"></i> Account Email
                </div>
                <div class="settings-sidebar-item" data-settings-panel="account" onclick="switchSettingsPanel('account')">
                    <i class="fas fa-user-shield"></i> Utenti webmail
                </div>
                <div class="settings-sidebar-item" data-settings-panel="log" onclick="switchSettingsPanel('log')">
                    <i class="fas fa-history"></i> Log Accessi
                </div>
            </div>

            <!-- Contenuto pannello attivo -->
            <div class="settings-content">

                <!-- ============ PANNELLO: ACCOUNT EMAIL (IMAP Dovecot + SMTP) ============ -->
                <div id="settingsPanelMailAccounts" class="settings-panel active">
                    <div class="identita-actions">
                        <button class="btn-identita-add" onclick="openAddMailAccountForm()">
                            <i class="fas fa-plus"></i> Nuovo Account Email
                        </button>
                        <button class="btn-identita-refresh" onclick="loadMailAccounts()">
                            <i class="fas fa-sync"></i> Aggiorna
                        </button>
                    </div>

                    <p class="settings-panel-hint">
                        Ogni account legge la posta da Dovecot (IMAP, utente Linux del server) e la invia tramite
                        il proprio server SMTP. Un account può essere visibile a <strong>tutti</strong> gli utenti
                        della webmail oppure solo a quelli <strong>selezionati</strong>. Le password sono salvate
                        cifrate e non vengono mai mostrate.
                    </p>

                    <div class="accounts-table-container">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Email</th>
                                    <th>IMAP</th>
                                    <th>SMTP</th>
                                    <th>Visibilità</th>
                                    <th style="text-align:center;">Attivo</th>
                                    <th>Ultimo test</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody id="mailAccountsTableBody">
                                <tr>
                                    <td colspan="8" class="loading-accounts">Caricamento account...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div id="mailAccountFormContainer" style="display:none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e9ecef;">
                        <h4 id="mailAccountFormTitle">Nuovo Account Email</h4>
                        <form id="mailAccountForm" onsubmit="return false;" autocomplete="off">
                            <input type="hidden" id="maId" name="id">

                            <h5 class="mail-account-section"><i class="fas fa-user"></i> Generale</h5>
                            <div class="form-row">
                                <label>Nome account <span class="required">*</span></label>
                                <input type="text" id="maNome" name="nome_visualizzato" required placeholder="es. Ufficio commerciale">
                            </div>
                            <div class="form-row">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" id="maEmail" name="email" required placeholder="info@dominio.it">
                            </div>
                            <div class="form-row">
                                <label>Società / nome mittente</label>
                                <input type="text" id="maSocieta" name="societa" placeholder="Nome mostrato ai destinatari">
                            </div>

                            <h5 class="mail-account-section"><i class="fas fa-inbox"></i> Server IMAP (Dovecot)</h5>
                            <div class="mail-account-grid">
                                <div class="form-row">
                                    <label>Server <span class="required">*</span></label>
                                    <input type="text" id="maImapHost" name="imap_host" required placeholder="IP del server">
                                </div>
                                <div class="form-row">
                                    <label>Porta <span class="required">*</span></label>
                                    <input type="number" id="maImapPort" name="imap_port" required value="143" min="1" max="65535">
                                </div>
                                <div class="form-row">
                                    <label>Sicurezza</label>
                                    <select id="maImapSecurity" name="imap_security" onchange="onMailSecurityChange('imap')">
                                        <option value="starttls">STARTTLS (consigliato)</option>
                                        <option value="ssl">SSL/TLS</option>
                                        <option value="none">Nessuna</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mail-account-grid">
                                <div class="form-row">
                                    <label>Utente Linux <span class="required">*</span></label>
                                    <input type="text" id="maImapUser" name="imap_username" required autocomplete="off">
                                </div>
                                <div class="form-row">
                                    <label>Password <span class="required mail-pw-required">*</span></label>
                                    <input type="password" id="maImapPassword" name="imap_password" autocomplete="new-password">
                                </div>
                            </div>

                            <h5 class="mail-account-section"><i class="fas fa-paper-plane"></i> Server SMTP (invio)</h5>
                            <div class="mail-account-grid">
                                <div class="form-row">
                                    <label>Server <span class="required">*</span></label>
                                    <input type="text" id="maSmtpHost" name="smtp_host" required placeholder="smtp.provider.it">
                                </div>
                                <div class="form-row">
                                    <label>Porta <span class="required">*</span></label>
                                    <input type="number" id="maSmtpPort" name="smtp_port" required value="587" min="1" max="65535">
                                </div>
                                <div class="form-row">
                                    <label>Sicurezza</label>
                                    <select id="maSmtpEncryption" name="smtp_encryption" onchange="onMailSecurityChange('smtp')">
                                        <option value="tls">STARTTLS (587)</option>
                                        <option value="ssl">SSL/TLS (465)</option>
                                        <option value="none">Nessuna</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mail-account-grid">
                                <div class="form-row">
                                    <label>Utente SMTP <span class="required">*</span></label>
                                    <input type="text" id="maSmtpUser" name="smtp_username" required autocomplete="off" placeholder="di solito l'indirizzo email">
                                </div>
                                <div class="form-row">
                                    <label>Password <span class="required mail-pw-required">*</span></label>
                                    <input type="password" id="maSmtpPassword" name="smtp_password" autocomplete="new-password">
                                </div>
                            </div>
                            <div id="maPasswordHint" class="password-hint" style="display:none;">
                                <i class="fas fa-info-circle"></i> Lascia vuote le password per mantenere quelle salvate
                            </div>

                            <h5 class="mail-account-section"><i class="fas fa-eye"></i> Visibilità</h5>
                            <div class="form-row mail-account-radio">
                                <label><input type="radio" name="visibilita" value="tutti" checked onchange="onMailVisibilityChange()"> Tutti gli utenti della webmail</label>
                                <label><input type="radio" name="visibilita" value="selezionati" onchange="onMailVisibilityChange()"> Solo gli utenti selezionati</label>
                            </div>
                            <div id="maUsersList" class="mail-account-users" style="display:none;"></div>

                            <div class="form-row">
                                <label class="settings-toggle">
                                    <input type="checkbox" id="maAttivo" checked>
                                    <span class="settings-toggle-slider"></span>
                                </label>
                                Account attivo
                            </div>

                            <div id="maTestResult" class="mail-account-test" style="display:none;"></div>

                            <div class="compose-modal-actions">
                                <button type="button" class="btn-identita-refresh" id="maTestBtn" onclick="testMailAccount()">
                                    <i class="fas fa-plug"></i> Testa connessione
                                </button>
                                <button type="button" class="btnsend" onclick="saveMailAccount()">
                                    <i class="fas fa-save"></i> Salva Account
                                </button>
                                <button type="button" class="btn-cancel" onclick="cancelMailAccountForm()">
                                    <i class="fas fa-times"></i> Annulla
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ============ PANNELLO: UTENTI WEBMAIL (accesso alla webapp) ============ -->
                <div id="settingsPanelAccount" class="settings-panel">
                    <div class="identita-actions">
                        <button class="btn-identita-add" onclick="openAddUserForm()">
                            <i class="fas fa-plus"></i> Nuovo Account
                        </button>
                        <button class="btn-identita-refresh" onclick="loadWebUsers()">
                            <i class="fas fa-sync"></i> Aggiorna
                        </button>
                    </div>

                    <p class="settings-panel-hint">
                        Account di accesso alla webapp. Solo l'amministratore può creare, modificare ed
                        eliminare account e assegnarne le password: non esiste un cambio password da parte
                        dell'utente stesso.
                    </p>

                    <div class="accounts-table-container">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Ruolo</th>
                                    <th style="text-align:center;">Attivo</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody id="webUsersTableBody">
                                <tr>
                                    <td colspan="6" class="loading-accounts">Caricamento account...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Form modale per nuovo/modifica account -->
                    <div id="userFormContainer" style="display:none; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e9ecef;">
                        <h4 id="userFormTitle">Nuovo Account</h4>
                        <form id="userAccountForm">
                            <input type="hidden" id="formUserId" name="id">

                            <div class="form-row">
                                <label>Nome <span class="required">*</span></label>
                                <input type="text" id="formUserNome" name="nome" required>
                            </div>

                            <div class="form-row">
                                <label>Cognome <span class="required">*</span></label>
                                <input type="text" id="formUserCognome" name="cognome" required>
                            </div>

                            <div class="form-row">
                                <label>Username <span class="required">*</span></label>
                                <input type="text" id="formUserUsername" name="username" required placeholder="es. mrossi">
                            </div>

                            <div class="form-row">
                                <label>Email <span class="required">*</span></label>
                                <input type="email" id="formUserEmail" name="email" required>
                            </div>

                            <div class="form-row">
                                <label>Ruolo <span class="required">*</span></label>
                                <select id="formUserTipo" name="tipo_user" required>
                                    <option value="user">Operatore</option>
                                    <option value="admin">Amministratore</option>
                                </select>
                            </div>

                            <div class="form-row">
                                <label class="settings-toggle">
                                    <input type="checkbox" id="formUserAttivo" checked>
                                    <span class="settings-toggle-slider"></span>
                                </label>
                                Account attivo
                            </div>

                            <div class="form-row" id="formUserPasswordRow">
                                <label>Password <span class="required">*</span></label>
                                <input type="password" id="formUserPassword" name="password" placeholder="Minimo 8 caratteri" autocomplete="new-password">
                            </div>

                            <div class="compose-modal-actions">
                                <button type="button" class="btnsend" onclick="saveUserAccount()">
                                    <i class="fas fa-save"></i> Salva Account
                                </button>
                                <button type="button" class="btn-cancel" onclick="cancelUserForm()">
                                    <i class="fas fa-times"></i> Annulla
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- ============ PANNELLO: LOG ACCESSI (nuovo) ============ -->
                <div id="settingsPanelLog" class="settings-panel">
                    <div class="identita-actions">
                        <input type="text" id="logSearchUsername" placeholder="Filtra per username..."
                               style="padding: 8px 12px; border: 1px solid #d1d5db; border-radius: 6px; margin-right: 8px;"
                               onkeydown="if(event.key==='Enter'){event.preventDefault(); loadAccessLog();}">
                        <button class="btn-identita-refresh" onclick="loadAccessLog()">
                            <i class="fas fa-sync"></i> Aggiorna
                        </button>
                    </div>

                    <p class="settings-panel-hint">
                        Storico degli accessi (login riusciti, tentativi falliti e logout) registrati per ogni account.
                        Vengono mostrate le ultime <?php echo (int)100; ?> voci corrispondenti al filtro.
                    </p>

                    <div class="accounts-table-container">
                        <table class="accounts-table">
                            <thead>
                                <tr>
                                    <th>Data/Ora</th>
                                    <th>Username</th>
                                    <th>Azione</th>
                                    <th style="text-align:center;">Esito</th>
                                    <th>IP</th>
                                    <th>User Agent</th>
                                </tr>
                            </thead>
                            <tbody id="accessLogTableBody">
                                <tr>
                                    <td colspan="6" class="loading-accounts">Caricamento log...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ==================== FINESTRA ASPETTO (tutti gli utenti, tema.js) ==================== -->
<div id="aspettoModal" class="compose-modal-overlay" style="display:none;" onclick="if (event.target === this) chiudiAspetto()">
    <div class="compose-modal aspetto-modal" role="dialog" aria-labelledby="aspettoTitolo">
        <div class="compose-modal-header">
            <h3 id="aspettoTitolo"><i class="fas fa-palette"></i> Aspetto</h3>
            <button class="btn-close-compose" onclick="chiudiAspetto()" title="Chiudi">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="aspetto-corpo">
            <h4>Colore di base</h4>
            <p class="settings-panel-hint">Vale solo per il tuo utente, su qualsiasi computer. Cliccando un colore vedi subito l'anteprima.</p>
            <div id="aspettoTemi" class="aspetto-temi"></div>
            <?php if (isAdmin()): ?>
            <div class="aspetto-predefinito">
                <h4>Colore predefinito per tutti</h4>
                <p class="settings-panel-hint">Usato nella pagina di accesso e dagli utenti che non hanno scelto un colore.</p>
                <label class="aspetto-check">
                    <input type="checkbox" id="aspettoPredefinito">
                    Usa il colore scelto sopra come predefinito
                </label>
            </div>
            <?php endif; ?>
        </div>
        <div class="compose-modal-actions">
            <button type="button" class="btnsend" id="aspettoSalva" onclick="salvaAspetto()">
                <i class="fas fa-check"></i> Salva
            </button>
            <button type="button" class="btn-cancel" onclick="chiudiAspetto()">Annulla</button>
        </div>
    </div>
</div>

<!-- Modal Preview Allegati -->
<div id="previewModal" class="preview-modal">
    <div class="preview-modal-content">
        <div class="preview-modal-header">
            <h3 id="previewTitle">Anteprima Allegato</h3>
            <button id="previewCloseBtn" class="btn-close-preview">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div id="previewContent" class="preview-modal-body">
            <!-- Contenuto caricato dinamicamente -->
        </div>
    </div>
</div>

<!-- ==================== FINE COMPOSE MODAL ==================== -->

<!-- Modale Nuove Email -->
<div id="newEmailsModal" class="new-emails-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-envelope-open"></i> Email in Arrivo Non Lette</h2>
            <div class="modal-actions">
                <button class="modal-btn minimize" title="Minimizza" >
                    <i class="fas fa-window-minimize"></i>
                </button>
                <button class="modal-btn close" title="Chiudi" onclick="closeNewEmailsModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div class="modal-body">
            <div id="newEmailsContent">
                <!-- Contenuto caricato dinamicamente -->
            </div>
        </div>
    </div>
</div>

    <!-- Editor HTML per email -->
    <script src="email_editor.js"></script>
    <script src="archivio.js?v=2.2"></script>
    <script src="vendor/dompurify/purify.min.js?v=3.2.4"></script>
    <script src="contatti.js?v=2"></script>
    <script src="selezione.js?v=3"></script>
    <script src="ricerca.js?v=1"></script>
    <script src="bozze.js?v=1"></script>
    <script src="cartelle.js?v=1"></script>
    <script src="filtri.js?v=1"></script>
    <script src="tema.js?v=1"></script>
    <script src="telefono.js?v=1"></script>
        
    <script>
        let currentEmailFile = '';
        let isResizing = false;
        let lastX = 0;
        let sidebarWidth = 380;    // Larghezza di default dell'elenco email
        let maincontentWidth = 0;  // margine sinistro della lettura
        
        // Variabili per la paginazione
        let currentPage = 1;
        let emailsPerPage = 20;
        let totalEmails = 0;
        let totalPages = 1;
        
        // Variabile per la cartella selezionata
        let currentFolder = 'inbox'; // Default: inbox
        let emailFolder = 'inbox'; // ✅ NUOVA: Cartella dell'email attualmente visualizzata

        // ==================== GESTIONE MODALE NUOVE EMAIL - VERSIONE PULITA ====================
        let newEmailsModalOpen = false;
        let newEmailsData = [];
        let selectedFiles = [];          // Allegati manuali (upload)
        let forwardedAttachmentsList = []; // Allegati originali da inoltrare (con relativo indice nell'email sorgente)
        let currentEmails = [];
        let currentEmailData = null; // ✅ Cache dati email corrente (allegati, corpo, ecc.)

        let isSwitchingFolder = false; // ✅ NUOVA: Flag per bloccare sovrapposizioni cambio cartella

        // Carica gli utenti all'avvio
        window.onload = function() {
            loadUsers();
            
            // Setup resizer
            const resizer = document.getElementById('resizer');
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            if (resizer) {
                resizer.addEventListener('mousedown', initResize);
            }
            document.addEventListener('mousemove', resize);
            document.addEventListener('mouseup', stopResize);
            
            // Imposta larghezza iniziale
            sidebar.style.width = sidebarWidth + 'px';
            mainContent.style.marginLeft = maincontentWidth + 'px';
            
            // Carica larghezza salvata (se esiste)
            const savedWidth = localStorage.getItem('sidebarWidth');
            if (savedWidth) {
                sidebarWidth = parseInt(savedWidth);
                sidebar.style.width = sidebarWidth + 'px';
                mainContent.style.marginLeft = maincontentWidth + 'px';
            }
            // Sincronizza i campi di ricerca
            syncSearchFields();
            
            // AGGIUNGI QUESTA RIGA
            updateReplyButtonState();
                    
        };

    // ==================== LAYOUT SU SCHERMI STRETTI ====================
    // Sul telefono si vede un riquadro alla volta: le cartelle si aprono a
    // scomparsa (☰) e la lettura sostituisce l'elenco (classe "leggendo").
    function toggleCartelle(event) {
        if (event) event.stopPropagation();
        document.body.classList.toggle('cartelle-aperte');
    }
    function chiudiCartelle() {
        document.body.classList.remove('cartelle-aperte');
    }
    function tornaAllElenco() {
        document.body.classList.remove('leggendo');
    }
    document.addEventListener('click', function(e) {
        if (document.body.classList.contains('cartelle-aperte')
            && !e.target.closest('#pannelloCartelle') && !e.target.closest('.btn-menu-cartelle')) {
            chiudiCartelle();
        }
    });

    // ==================== FUNZIONI DI ESCAPE SICURO ====================
    /**
     * Escape HTML per contenuto testuale
     */
    function escapeHtml(text) {
        if (!text) return '';
        return text.toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /**
     * Valore da mettere tra apici singoli dentro un onclick="..." e da
     * rileggere con decodeURIComponent: encodeURIComponent lascia l'apice.
     */
    function encJs(v) {
        return encodeURIComponent(v == null ? '' : String(v)).replace(/'/g, '%27');
    }

    /**
     * HTML di un'email (non fidato) da inserire nella pagina, per esempio
     * nell'editor quando si risponde o si inoltra: DOMPurify toglie script,
     * attributi on*, link javascript: e altro. Via anche <base> (cambierebbe
     * l'indirizzo di riferimento di tutta la pagina), <style> (cambierebbe lo
     * stile della webmail) e i moduli.
     */
    function pulisciHtmlEmail(html) {
        if (!html) return '';
        if (typeof DOMPurify === 'undefined') {
            // Libreria non caricata: meglio solo testo che HTML non pulito
            return escapeHtml(html.replace(/<[^>]*>/g, ' '));
        }
        return DOMPurify.sanitize(html, {
            FORBID_TAGS: ['style', 'base', 'link', 'meta', 'form', 'input', 'button', 'textarea', 'select', 'option'],
            FORBID_ATTR: ['id', 'name', 'action', 'formaction']
        });
    }

    /** Nome dell'allegato i-esimo dell'email aperta (per i pulsanti Scarica). */
    function nomeAllegatoCorrente(i) {
        const att = currentEmailData && currentEmailData.attachments && currentEmailData.attachments[i];
        return att ? String(att.filename || '').replace(/[\r\n]/g, ' ').trim() : '';
    }

    /**
     * Escape HTML per attributi
     */
    function escapeHtmlAttr(text) {
        if (!text) return '';
        return text.toString()
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /**
     * Costruisce un documento HTML minimale per il rendering del corpo email
     * dentro l'iframe sandboxato #detailBodyHtml (o l'anteprima di stampa).
     * L'HTML dell'email non viene MAI inserito nel documento principale
     * dell'app: un mittente potrebbe includere un tag <base> che dirotta
     * tutte le richieste fetch relative della pagina, o script/handler
     * onerror malevoli.
     */
    function buildEmailIframeDoc(bodyHtml) {
        // <base target="_blank"> va per primo: vale il primo <base> con target,
        // quindi un <base> nell'email non lo scavalca. no-referrer: il sito
        // aperto non vede l'indirizzo della webmail.
        return '<!DOCTYPE html><html><head><meta charset="UTF-8">'
            + '<base target="_blank"><meta name="referrer" content="no-referrer"><style>'
            + 'body{font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;margin:0;padding:12px;word-wrap:break-word;overflow-wrap:break-word;}'
            + 'img{max-width:100%;height:auto;}'
            + '</style></head><body>' + (bodyHtml || '') + '</body></html>';
    }

    /**
     * Evidenzia il termine di ricerca nei testi (con escape dei caratteri speciali regex)
     */
    function highlightSearchTerm(text, searchTerm) {
        if (!searchTerm || !text) return text;
        const regex = new RegExp('(' + searchTerm.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }
    // ===================================================================        
        // Funzione per caricare gli utenti
        function loadUsers() {
            
            const select = document.getElementById('userSelect');
            if (!select) {
                return;
            }
            
            // Mostra messaggio di caricamento
            select.innerHTML = '<option value="">Caricamento account...</option>';
            
            fetch('get_users.php')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Errore HTTP: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    
                    // Reset select
                    select.innerHTML = '<option value="">-- Seleziona un account --</option>';
                    
                    if (data.error) {
                        const option = document.createElement('option');
                        option.value = '';
                        option.textContent = '❌ Errore: ' + data.error;
                        option.disabled = true;
                        select.appendChild(option);
                        return;
                    }
                    
                    if (!data.users || data.users.length === 0) {
                        const option = document.createElement('option');
                        option.value = '';
                        option.textContent = is_admin
                            ? '-- Nessun account: creane uno in Impostazioni → Account Email --'
                            : '-- Nessun account disponibile --';
                        option.disabled = true;
                        select.appendChild(option);
                        return;
                    }
                    
                    data.users.forEach(user => {
                        const option = document.createElement('option');
                        // value = id dell'account email (passato come "user" agli endpoint),
                        // textContent = "Nome account <email>"
                        option.value = user.utente;
                        option.textContent = user.nickname || user.utente;
                        select.appendChild(option);
                    });
                    
                })
                .catch(error => {
                    select.innerHTML = '<option value="">❌ Errore: ' + error.message + '</option>';
                    showToast('Errore nel caricamento degli account: ' + error.message, 'error');
                });
        }

        // Estrae l'indirizzo email da una stringa (gestisce "Nome <email@example.com>")
        function extractEmailAddress(str) {
            if (!str) return null;
            
            // Prova prima con il formato "Nome <email@example.com>"
            const match = str.match(/<([^>]+)>/);
            if (match && match[1]) {
                return match[1].trim();
            }
            
            // Altrimenti cerca un pattern email nella stringa
            const emailMatch = str.match(/[\w.-]+@[\w.-]+\.\w+/);
            if (emailMatch) {
                return emailMatch[0].trim();
            }
            
            return null;
        }

        /**
         * Carica le email per l'utente e la cartella specificata
         * VERSIONE DEFINITIVA - Cartella passata ESPPLICITAMENTE per evitare race condition
         * 
         * CORREZIONI FONDAMENTALI:
         * ✅ PARAMETRO folderOverride OBBLIGATORIO per switchFolder (evita dipendenza da currentFolder)
         * ✅ VALIDAZIONE IMMEDIATA folderOverride prima di qualsiasi operazione
         * ✅ USA SOLO folderToUse (mai currentFolder) nella richiesta fetch
         * ✅ RESET isSwitchingFolder GARANTITO in .finally()
         * ✅ PERPAGE letto SEMPRE dall'UI (mai cache)
         */
        function loadEmails(page = 1, folderOverride = null) {
            // ✅ VALIDAZIONE CRITICA: Determina cartella da usare PRIMA di qualsiasi operazione
            let folderToUse = folderOverride 
                ? folderOverride 
                : (currentFolder || 'inbox');
            
            // Elementi DOM
            const userSelect = document.getElementById('userSelect');
            const emailList = document.getElementById('emailList');
            const loading = document.getElementById('loadingSidebar');
            const emailCount = document.getElementById('emailCount');
            const contentTitle = document.getElementById('contentTitle');
            const emptyState = document.getElementById('emptyState');
            const perPageSelect = document.getElementById('perPageSelect');
            
            // Validazione account (l'id scelto diventa l'account corrente, usato
            // dai controlli "email selezionata" di risposta, cestino, ecc.)
            const user = userSelect?.value?.trim();
            currentUser = user || '';

            // Cambio account: le cartelle create dall'utente sono di quell'account,
            // quindi si ricaricano e, se ne era aperta una, si torna alla Posta in arrivo
            if ((user || '') !== altreCartelleAccount) {
                if (folderToUse.startsWith('imap:')) {
                    folderToUse = 'inbox';
                    currentFolder = 'inbox';
                    emailFolder = 'inbox';
                    document.querySelectorAll('.folder-btn').forEach(btn =>
                        btn.classList.toggle('active', btn.getAttribute('data-folder') === 'inbox'));
                }
                loadAltreCartelle(user || '');
            }

            if (!user) {
                if (emailList) emailList.innerHTML = '';
                if (emailCount) emailCount.textContent = '0 email';
                if (contentTitle) contentTitle.textContent = 'Seleziona un account per visualizzare le email';
                if (emptyState) emptyState.style.display = 'block';
                updatePaginationInfo(0, 0, 1, 1);
                // Senza account non parte nessuna richiesta (e quindi nessun .finally):
                // sblocca qui i pulsanti delle cartelle bloccati da switchFolder
                if (isSwitchingFolder) {
                    document.querySelectorAll('.folder-btn').forEach(btn => {
                        btn.disabled = false;
                        btn.style.opacity = '';
                        btn.style.cursor = '';
                    });
                    isSwitchingFolder = false;
                }
                return;
            }
            
            // ✅ USA SEMPRE folderToUse (MAI currentFolder) per coerenza
            const limit = parseInt(perPageSelect?.value || 20, 10);
            const startTime = Date.now();
            
            // UI loading
            if (loading) loading.style.display = 'flex';
            if (emailList) emailList.innerHTML = '';
            if (emailCount) emailCount.textContent = 'Caricamento...';
            
            // ✅ FETCH CON folderToUse ESPRESSO (NESSUNA DIPENDENZA DA currentFolder)
            // ✅ CORREZIONE FONDAMENTALE: Leggi SEMPRE l'utente dal dropdown (non dalla variabile globale)
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;
            // ✅ FETCH CORRETTO PER CARICAMENTO LISTA EMAIL (senza riferimenti a 'file')
            fetch('get_emails.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `user=${encodeURIComponent(requestUser)}&page=${encodeURIComponent(page)}&limit=${encodeURIComponent(limit)}&folder=${encodeURIComponent(folderToUse)}`
            })
            .then(response => {
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                return response.json();
            })
            .then(data => {
                if (loading) loading.style.display = 'none';
                const loadTime = ((Date.now() - startTime) / 1000).toFixed(3);
                
                // === VALIDAZIONE RISPOSTA ===
                if (!data || !Array.isArray(data.emails)) {
                    throw new Error('Struttura dati non valida: manca array "emails"');
                }
                
                // ✅ GESTIONE CARTella VUOTA (SENZA DUPLICAZIONI)
                if (data.emails.length === 0) {
                    if (emailList) emailList.innerHTML = '<div class="no-emails">📭 Nessuna email trovata in questa cartella</div>';
                    if (emptyState) emptyState.style.display = 'block';
                    updatePaginationInfo(0, 0, 1, 1);
                    
                    // Chiudi pannello dettagli
                    const emailDetail = document.getElementById('emailDetail');
                    if (emailDetail) emailDetail.style.display = 'none';
                    
                    return;
                }
                
                // === ELABORAZIONE EMAIL ===
                
                // Aggiorna contatori GLOBALI (solo se non c'è override)
                if (folderOverride === null) {
                    currentFolder = folderToUse; // Sincronizza stato globale
                    totalEmails = data.total || 0;
                    totalPages = data.total_pages || 1;
                    currentPage = page;
                }
                
                // Aggiorna UI
                if (emailCount) {
                    const totale = (data.total || 0).toLocaleString('it-IT');
                    emailCount.innerHTML = `<span class="elenco-cartella">${escapeHtml(nomeCartella(folderToUse))}</span>`
                        + `<span class="elenco-totale">${totale} email${loadTime > 1 ? ` · ${loadTime}s` : ''}</span>`;
                    emailCount.setAttribute('data-new-count', data.new_count || 0);
                }
                
                // Genera HTML email
                let html = '';
                data.emails.forEach((email, index) => {
                    const isActive = false;
                    let displaySender = email.sender || 'Sconosciuto';
                    
                    // Per Sent, mostra destinatario
                    if (folderToUse === 'sent' && email.recipient) {
                        displaySender = email.recipient;
                    }
                    
                    // Icona email (solo Inbox/Sent)
                    let emailListIcon = '';
                    if ((folderToUse === 'inbox' || folderToUse === 'sent') && displaySender) {
                        const extractedEmail = extractEmailAddress(displaySender);
                        if (extractedEmail) {
                            emailListIcon = `<span class="email-list-icon" data-email="${escapeHtmlAttr(extractedEmail)}" title="Scrivi a ${escapeHtmlAttr(extractedEmail)}">
                                <i class="fas fa-envelope"></i>
                            </span>`;
                        }
                    }
                    
                    // Icona allegati
                    const attachmentIcon = email.has_attachments 
                        ? '<span class="attachment-indicator" title="Ha allegati"><i class="fas fa-paperclip"></i></span>' 
                        : '';
                    
                    // Classe per email nuove/lette
                    const isNewClass = email.is_new ? 'email-new' : 'email-read';
                    
                    html += `
                        <div class="email-item ${isActive ? 'active' : ''} ${isNewClass}" 
                            data-file="${escapeHtmlAttr(email.file)}"
                            data-folder="${escapeHtmlAttr(folderToUse)}"
                            ${email.is_flagged ? 'data-importante="1"' : ''}
                            data-index="${index}">
                            <div class="email-item-header">
                                <div class="email-sender-container">
                                    <div class="email-sender-text" title="${escapeHtmlAttr(displaySender)}">
                                        ${escapeHtml(displaySender)}
                                    </div>
                                    <div class="email-icons-container">
                                        ${emailListIcon}
                                        ${attachmentIcon}
                                    </div>
                                </div>
                                <div class="email-date">${escapeHtml(email.date || 'Data sconosciuta')}</div>
                            </div>
                            <div class="email-item-body">
                                <div class="email-subject-preview" title="${escapeHtmlAttr(email.subject || '(nessun oggetto)')}">
                                    ${escapeHtml(email.subject || '(nessun oggetto)')}
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                if (emailList) {
                    emailList.innerHTML = html;
                    
                    // Aggiungi event listeners
                    emailList.querySelectorAll('.email-item').forEach(item => {
                        item.addEventListener('click', function() {
                            const file = this.getAttribute('data-file');
                            showEmailDetails(file, this);
                        });
                    });
                }
                
                // Aggiorna stato applicazione
                updateCurrentEmails(data.emails);
                updatePaginationInfo(data.total || 0, limit, page, data.total_pages || 1);
                
                // UI finale
                updateActionButtons();
                protectUserSelectContainer();
            })
            .catch(error => {
                if (loading) loading.style.display = 'none';
                
                const errorMsg = error.message?.includes('HTML') 
                    ? 'Errore di comunicazione con il server' 
                    : (error.message || 'Errore durante il caricamento');
                
                showToast(`Errore: ${errorMsg}`, 'error');
                
                if (emailList) {
                    emailList.innerHTML = `
                        <div class="empty-state">
                            <i class="fas fa-${error.message?.includes('404') ? 'exclamation-triangle' : 'wifi-slash'}"></i>
                            <p>${escapeHtml(errorMsg)}</p>
                            <button onclick="loadEmails(${page}, ${escapeHtmlAttr(JSON.stringify(folderToUse))})" class="retry-btn">
                                <i class="fas fa-redo"></i> Riprova
                            </button>
                        </div>
                    `;
                }
                if (emptyState) emptyState.style.display = 'block';
                updatePaginationInfo(0, 0, 1, 1);
                
                // Chiudi pannello dettagli
                const emailDetail = document.getElementById('emailDetail');
                if (emailDetail) emailDetail.style.display = 'none';
            })
            .finally(() => {
                // ✅ RESET GARANTITO isSwitchingFolder (CRITICO PER EVITARE BLOCCHI)
                if (typeof isSwitchingFolder !== 'undefined' && isSwitchingFolder) {
                    document.querySelectorAll('.folder-btn').forEach(btn => {
                        btn.disabled = false;
                        btn.style.opacity = '';
                        btn.style.cursor = '';
                    });
                    isSwitchingFolder = false;
                }
            });
        }        
        /**
         * Carica nel pannello cartelle quelle create dall'utente sul server
         * (oltre alle cinque standard), con rientro per le sottocartelle.
         */
        function loadAltreCartelle(account) {
            const contenitore = document.getElementById('altreCartelle');
            altreCartelleAccount = account;
            altreCartelle = {};
            if (!contenitore) return;
            contenitore.innerHTML = '';
            if (!account) return;

            fetch('get_folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `user=${encodeURIComponent(account)}`
            })
            .then(r => r.json())
            .then(data => {
                // Risposta arrivata dopo un nuovo cambio di account: si ignora
                if (altreCartelleAccount !== account) return;
                if (data.error) {
                    console.warn('Cartelle non caricate:', data.error);
                    return;
                }
                const cartelle = data.cartelle || [];
                cartelle.forEach(c => { altreCartelle[c.id] = c.percorso; });
                // Copia locale: le modifiche alle cartelle valgono solo per la copia sul server (cartelle.js)
                contenitore.dataset.soloLocale = data.solo_locale ? '1' : '';
                contenitore.innerHTML = cartelle.map(c => {
                    const rientro = `style="--livello: ${Math.min(c.livello, 6)}"`;
                    if (!c.apribile) {
                        return `<div class="cartella-gruppo" data-folder="${escapeHtmlAttr(c.id)}" data-modificabile="${c.modificabile ? 1 : ''}" ${rientro} title="${escapeHtmlAttr(c.percorso)}">`
                            + `<i class="far fa-folder"></i><span>${escapeHtml(c.nome)}</span></div>`;
                    }
                    const attiva = c.id === currentFolder ? ' active' : '';
                    return `<button type="button" class="folder-btn${attiva}" data-folder="${escapeHtmlAttr(c.id)}" data-modificabile="${c.modificabile ? 1 : ''}" ${rientro} title="${escapeHtmlAttr(c.percorso)}">`
                        + `<i class="fas fa-folder"></i><span>${escapeHtml(c.nome)}</span></button>`;
                }).join('');
                contenitore.querySelectorAll('.folder-btn').forEach(btn => {
                    btn.addEventListener('click', () => switchFolder(btn.getAttribute('data-folder')));
                });
            })
            .catch(err => console.warn('Cartelle non caricate:', err));
        }

        // Funzione per ottenere l'icona della cartella
        function getFolderIcon(folder) {
            const icons = {
                'inbox': 'inbox',
                'sent': 'paper-plane',
                'draft': 'edit',
                'junk': 'exclamation-triangle',
                'trash': 'trash'
            };
            return icons[folder] || 'folder';
        }

        // Funzione per mostrare i dettagli dell'email                                                                                                                                                                                                           
        function showEmailDetails(file, element) {

            if (!file || !element) {
                showToast('Errore: Dati email non validi', 'error');
                return;
            }

            // Determina la cartella corretta
            let emailFolderLocal = 'inbox';
            
            if (element && element.hasAttribute('data-folder')) {
                emailFolderLocal = element.getAttribute('data-folder');
            } else {
                const activeFolderBtn = document.querySelector('.folder-btn.active');
                if (activeFolderBtn) {
                    emailFolderLocal = activeFolderBtn.getAttribute('data-folder');
                } else {
                    emailFolderLocal = currentFolder;
                }
            }
            
            // ✅ AGGIORNA LA VARIABILE GLOBALE
            emailFolder = emailFolderLocal;
            
            currentEmailFile = file;
            currentEmailData = null; // ✅ Reset cache al cambio email
            
            // Rimuovi classe active da tutti gli elementi
            document.querySelectorAll('.email-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Aggiungi classe active all'elemento cliccato
            if (element) {
                element.classList.add('active');
            }
            
            // Verifica se è una email nuova (da spostare in cur)
            const isNewEmail = element && element.classList.contains('email-new');
            
            const emailDetail = document.getElementById('emailDetail');
            const loading = document.getElementById('loadingDetail');
            const emptyState = document.getElementById('emptyState');
            const contentTitle = document.getElementById('contentTitle');
            const downloadAllBtn = document.getElementById('downloadAllBtn');

            loading.style.display = 'flex';
            emailDetail.style.display = 'none';
            emptyState.style.display = 'none';
            contentTitle.textContent = 'Caricamento email...';

            const processEmail = async () => {
                try {
                    // Carica dettagli email (USA PERCORSO ASSOLUTO + CREDENTIALS)
                    // ✅ SOSTITUISCI CON QUESTO (legge SEMPRE dal dropdown)
                    const userSelect = document.getElementById('userSelect');
                    const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;

                    const detailResponse = await fetch('get_email_detail.php', { // ✅ ASSOLUTO
                        method: 'POST',
                        credentials: 'include', // ✅ OBBLIGATORIO
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(file)}&folder=${encodeURIComponent(emailFolder)}`
                    });
                    if (!detailResponse.ok && detailResponse.status !== 404) throw new Error('Errore HTTP: ' + detailResponse.status);
                    
                    let data = await detailResponse.json();
                    loading.style.display = 'none';
                    
                    if (data.error) {
                        showToast('Errore: ' + data.error, 'error');
                        contentTitle.textContent = 'Errore caricamento';
                        emptyState.style.display = 'block';
                        return;
                    }

                    // Il server IMAP segna l'email come letta all'apertura:
                    // aggiorna subito la riga e il contatore delle non lette
                    if (isNewEmail) {
                        element.classList.remove('email-new');
                        element.classList.add('email-read');
                        updateNewEmailsBadge();
                    }

                    // ... [CODICE ESISTENTE PER MOSTRARE DETTAGLI EMAIL - SENZA MODIFICHE] ...
                    // Aggiorna titolo, mittente, destinatario, data, corpo email
                    contentTitle.textContent = data.subject || '(nessun oggetto)';
                    document.getElementById('detailSubject').textContent = data.subject || '(nessun oggetto)';
                    document.getElementById('detailFrom').textContent = data.from || 'Sconosciuto';
                    if (typeof ContattiRubrica !== 'undefined') ContattiRubrica.proponiMittente(data.from || '');
                    const avatar = document.getElementById('detailAvatar');
                    if (avatar) {
                        const nomeMittente = String(data.from || '?').replace(/^["'\s]+/, '');
                        avatar.textContent = (nomeMittente.charAt(0) || '?').toUpperCase();
                    }
                    document.getElementById('detailTo').textContent = data.to || 'Sconosciuto';
                    document.getElementById('detailDate').textContent = data.date_formatted || data.date;

                    // Mostra corpo email
                    const htmlBodyDiv = document.getElementById('detailBodyHtml');
                    const textBodyPre = document.getElementById('detailBodyText');
                    // Ridimensiona l'iframe all'altezza reale del contenuto (altrimenti
                    // resterebbe fisso all'altezza minima da CSS, "piccolo" per email
                    // corte e tagliato per email lunghe): richiede allow-same-origin nel
                    // sandbox, sicuro perché non è concesso allow-scripts.
                    htmlBodyDiv.onload = function() {
                        try {
                            const h = htmlBodyDiv.contentWindow.document.documentElement.scrollHeight;
                            htmlBodyDiv.style.height = Math.max(h, 150) + 'px';
                            // Anche i link con un target proprio: nuova scheda, senza
                            // accesso a questa pagina (noopener); le ancore interne restano.
                            htmlBodyDiv.contentWindow.document.querySelectorAll('a[href], area[href]').forEach(function(a) {
                                if ((a.getAttribute('href') || '').charAt(0) === '#') { a.target = '_self'; return; }
                                a.target = '_blank';
                                a.rel = 'noopener noreferrer';
                            });
                        } catch (e) {}
                    };
                    if (data.html_body) {
                        htmlBodyDiv.srcdoc = buildEmailIframeDoc(data.html_body);
                        textBodyPre.textContent = data.text_body || stripHtml(data.html_body);
                    } else if (data.text_body) {
                        htmlBodyDiv.srcdoc = buildEmailIframeDoc('<pre style="white-space: pre-wrap; word-wrap: break-word;">' + escapeHtml(data.text_body) + '</pre>');
                        textBodyPre.textContent = data.text_body;
                    } else {
                        htmlBodyDiv.srcdoc = buildEmailIframeDoc('<p><em>Nessun contenuto disponibile</em></p>');
                        textBodyPre.textContent = 'Nessun contenuto disponibile';
                    }

                    // ✅ Cache email corrente per evitare doppia chiamata in previewAttachment
                    currentEmailData = data;

                    // ✅ CORREZIONE ALLEGATI: Usa SEMPRE emailFolder nei link
                    const attachmentsSection = document.getElementById('attachmentsSection');
                    const attachmentsList = document.getElementById('attachmentsList');
                    
                    if (data.attachments && data.attachments.length > 0) {
                        attachmentsSection.style.display = 'block';
                        // Mostra pulsante "Scarica tutti" con emailFolder
                        if (downloadAllBtn) {
                            downloadAllBtn.style.display = (data.attachments.length > 1) ? 'inline-flex' : 'none';
                        }
                        
                        let attachmentsHtml = '';
                        for (let i = 0; i < data.attachments.length; i++) {
                            const att = data.attachments[i];
                            // Normalizza il nome file: rimuove \r\n che causerebbero SyntaxError in onclick
                            const safeName = (att.filename || '').replace(/[\r\n]/g, ' ').trim();
                            const icon = getAttachmentIcon(safeName);
                            const size = formatFileSize(att.size);
                            const fileType = getFileType(att.content_type);
                            const fileExt = getFileExtension(safeName);
                            const canPreview = isPreviewable(fileExt);

                            attachmentsHtml += '<div class="attachment-card' + (canPreview ? ' previewable' : '') + '">';
                            attachmentsHtml += '<div class="attachment-icon">' + icon + '</div>';
                            attachmentsHtml += '<div class="attachment-info">';
                            // Il nome del file viene dal mittente: sempre con escape. Nei
                            // pulsanti non entra nel codice dell'onclick: lo si rilegge da
                            // currentEmailData con l'indice dell'allegato.
                            const nomeAttr = escapeHtmlAttr(safeName);
                            const scarica = `downloadAttachmentViaFetch('${encJs(currentUser)}', '${encJs(file)}', '${i}', '${encJs(emailFolder)}', nomeAllegatoCorrente(${i}))`;
                            attachmentsHtml += '<div class="attachment-name" title="' + nomeAttr + '">' + escapeHtml(truncateFilename(safeName, 25)) + '</div>';
                            attachmentsHtml += '<div class="attachment-meta"><span class="attachment-size">' + escapeHtml(size) + '</span><span class="attachment-type">' + escapeHtml(fileType) + '</span></div></div>';

                            if (canPreview) {
                                attachmentsHtml += '<div class="attachment-actions">';
                                attachmentsHtml += `<button class="btn-preview" onclick="previewAttachment(${i})" title="Visualizza ${nomeAttr}"><i class="fas fa-eye"></i></button>`;
                                attachmentsHtml += `<button type="button" class="btn-download-small"
                                    onclick="${scarica}"
                                    title="Scarica ${nomeAttr}">
                                    <i class="fas fa-download"></i>
                                </button>`;
                                attachmentsHtml += '</div>';
                            } else {
                                attachmentsHtml += `<button type="button" class="btn-download-attachment"
                                    onclick="${scarica}"
                                    title="Scarica ${nomeAttr}">
                                    <i class="fas fa-download"></i> Scarica
                                </button>`;
                            }
                            attachmentsHtml += '</div>';
                        }
                        attachmentsList.innerHTML = attachmentsHtml;
                    } else {
                        attachmentsSection.style.display = 'none';
                        if (downloadAllBtn) downloadAllBtn.style.display = 'none';
                    }

                    // Mostra dettagli email e reset tabs
                    emailDetail.style.display = 'block';
                    document.body.classList.add('leggendo');
                    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                    document.querySelector('.tab-btn:first-child').classList.add('active');
                    document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
                    document.getElementById('htmlTab').classList.add('active');
                    
                } catch (error) {
                    loading.style.display = 'none';
                    alert('Errore caricamento email: ' + error.message);
                    contentTitle.textContent = 'Errore';
                    emptyState.style.display = 'block';
                }
            };
            
            return processEmail();
        }

        // ==================== GESTIONE CAMBIO CARTELLA ====================
        /**
         * Cambia cartella e ricarica le email - VERSIONE CORRETTA
         * @param {string} folder - Nome della cartella (inbox, sent, draft, junk, trash)
         */
        function switchFolder(folder) {
            // ✅ BLOCCO SOVRAPPOSIZIONI: Ignora click se operazione in corso
            if (isSwitchingFolder) {
                showToast('Attendere il completamento del caricamento...', 'info');
                return;
            }
            
            // ✅ BLOCCA tutti i pulsanti cartella PRIMA di qualsiasi operazione
            isSwitchingFolder = true;
            document.querySelectorAll('.folder-btn').forEach(btn => {
                btn.disabled = true;
                btn.style.opacity = '0.6';
                btn.style.cursor = 'wait';
            });
            
            // ✅ PASSO 1: Aggiorna VISUALMENTE il bottone attivo
            document.querySelectorAll('.folder-btn').forEach(btn => btn.classList.remove('active'));
            const targetBtn = document.querySelector(`.folder-btn[data-folder="${CSS.escape(folder)}"]`);
            if (targetBtn) targetBtn.classList.add('active');
            
            // ✅ PASSO 2: AGGIORNA TUTTE LE VARIABILI DI STATO PRIMA DI CARICARE
            currentFolder = folder;      // Cartella corrente per la lista email
            emailFolder = folder;        // Cartella per nuove aperture email
            currentEmailFile = null;     // Resetta email aperta
            currentEmailData = null;     // ✅ Reset cache al cambio cartella
            currentPage = 1;             // Reset pagina
            
            // ✅ PASSO 3: Chiudi pannello dettagli email (se aperto)
            const emailDetail = document.getElementById('emailDetail');
            const emptyState = document.getElementById('emptyState');
            if (emailDetail) emailDetail.style.display = 'none';
            if (emptyState) emptyState.style.display = 'block';
            document.body.classList.remove('leggendo');
            
            // ✅ PASSO 4: Aggiorna UI (titolo, pulsanti)
            const contentTitle = document.getElementById('contentTitle');
            if (contentTitle) {
                contentTitle.textContent = nomeCartella(folder);
            }
            chiudiCartelle();
            updateReplyButtonState();
            
            // ✅ PASSO 5: CARICA EMAIL CON CARTella ESPRESSA (EVITA DIPENDENZA DA currentFolder)
            loadEmails(1, folder); // ← PASSA folder ESPPLICITAMENTE (NON usare currentFolder)
        }
        // ===================================================================

        // Funzioni di utilità
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
        }

        function getAttachmentIcon(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const icons = {
                'pdf': '📄',  // Documento PDF
                'doc': '📝',
                'docx': '📝',
                'xls': '📊',
                'xlsx': '📊',
                'jpg': '🖼️',  // Immagine
                'jpeg': '🖼️',
                'png': '🖼️',
                'gif': '🖼️',
                'bmp': '🖼️',
                'webp': '🖼️',
                'svg': '🖼️',
                'zip': '📁',
                'rar': '📁',
                '7z': '📁',
                'txt': '📝',
                'rtf': '📝',
                'mp3': '🎵',
                'mp4': '🎥',
                'wav': '🎵',
                'avi': '🎥',
                'mov': '🎥'
            };
            return icons[ext] || '📎';
        }

        function getFileType(contentType) {
            if (!contentType) return 'File';
            if (contentType.includes('image')) return 'Immagine';
            if (contentType.includes('pdf')) return 'PDF';
            if (contentType.includes('text')) return 'Testo';
            if (contentType.includes('audio')) return 'Audio';
            if (contentType.includes('video')) return 'Video';
            return 'File';
        }

        function truncateFilename(filename, maxLength) {
            if (filename.length <= maxLength) return filename;
            const parts = filename.split('.');
            const ext = parts.pop();
            const name = parts.join('.');
            const truncated = name.substring(0, maxLength - ext.length - 3);
            return `${truncated}...${ext}`;
        }

        function stripHtml(html) {
            // Estrazione testo via stringa/regex, MAI via DOM: assegnare HTML non fidato
            // a un elemento (anche se mai collegato al documento) fa comunque partire il
            // caricamento di risorse come <img onerror="...">.
            if (!html) return '';
            return html
                .replace(/<style[\s\S]*?<\/style>/gi, ' ')
                .replace(/<script[\s\S]*?<\/script>/gi, ' ')
                .replace(/<[^>]+>/g, ' ')
                .replace(/&nbsp;/gi, ' ')
                .replace(/&amp;/gi, '&')
                .replace(/&lt;/gi, '<')
                .replace(/&gt;/gi, '>')
                .replace(/&quot;/gi, '"')
                .replace(/&#0?39;/gi, "'")
                .replace(/\s+/g, ' ')
                .trim();
        }

        // Resizer functions
        function initResize(e) {
            isResizing = true;
            lastX = e.clientX;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
        }

        function resize(e) {
            if (!isResizing) return;
            
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            const dx = e.clientX - lastX;
            lastX = e.clientX;
            
            sidebarWidth = Math.max(250, Math.min(800, sidebarWidth + dx));
            
            sidebar.style.width = sidebarWidth + 'px';
            mainContent.style.marginLeft = maincontentWidth + 'px';
        }

        function stopResize() {
            if (isResizing) {
                isResizing = false;
                document.body.style.cursor = 'default';
                document.body.style.userSelect = 'auto';
                
                // Salva la larghezza
                localStorage.setItem('sidebarWidth', sidebarWidth);
            }
        }
        
        // Funzione per scaricare tutti gli allegati come ZIP
        /**
         * Scarica tutti gli allegati come archivio ZIP
         * Correzioni applicate:
         * ✅ Legge SEMPRE l'utente dal dropdown (#userSelect) invece di currentUser
         * ✅ Usa iframe invece di window.open per evitare nuova scheda con errore
         * ✅ Feedback utente con spinner
         * ✅ Logging esplicito per debug
         */
        function downloadAllAttachments() {
            // ✅ LEGGI SEMPRE l'utente DAL DROPDOWN (fonte di verità)
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value.trim() : currentUser;
            
            // Validazione critica
            if (!requestUser || !currentEmailFile || !emailFolder) {
                alert('❌ Impossibile scaricare allegati: dati email incompleti');
                return;
            }

            // ✅ USA requestUser invece di currentUser nel parametro URL
            const url = `download_all_attachments.php?user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder)}`;
            
            // Feedback utente
            const downloadAllBtn = document.getElementById('downloadAllBtn');
            const originalText = downloadAllBtn?.innerHTML || '<i class="fas fa-file-archive"></i> Scarica tutti (.zip)';
            
            if (downloadAllBtn) {
                downloadAllBtn.disabled = true;
                downloadAllBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparazione ZIP...';
            }
            
            // ✅ METODO SICURO: Usa iframe invece di window.open per evitare pagina di errore visibile
            const iframe = document.createElement('iframe');
            iframe.style.display = 'none';
            iframe.name = 'downloadFrame';
            
            // Gestione completamento
            iframe.onload = function() {
                setTimeout(() => {
                    if (downloadAllBtn) {
                        downloadAllBtn.disabled = false;
                        downloadAllBtn.innerHTML = originalText;
                    }
                    // Rimuovi iframe dopo 5 secondi (dopo completamento download)
                    setTimeout(() => {
                        if (document.body.contains(iframe)) {
                            document.body.removeChild(iframe);
                        }
                    }, 5000);
                }, 800);
            };
            
            document.body.appendChild(iframe);
            
            // Crea link temporaneo e attacca all'iframe
            const link = document.createElement('a');
            link.href = url;
            link.target = 'downloadFrame';
            link.style.display = 'none';
            document.body.appendChild(link);
            
            // Esegui download
            link.click();
            
            // Pulizia link dopo breve ritardo
            setTimeout(() => {
                if (document.body.contains(link)) {
                    document.body.removeChild(link);
                }
            }, 100);
            
        }
        // ==================== FUNZIONI DEI BOTTONI EMAIL ====================
        /**
         * Inoltra un'email con allegati originali preservati
         * Correzioni applicate:
         * ✅ Legge SEMPRE l'utente dal dropdown (#userSelect) invece di currentUser
         * ✅ Usa requestUser per TUTTI i riferimenti utente (incluso original_user)
         * ✅ Logging esplicito per debug immediato
         */
        function forwardEmail() {
            // ✅ PULISCI ALLEGATI PRIMA DI APRIRE IL COMPOSER
            clearAttachments();
            
            // ✅ LEGGI SEMPRE l'utente DAL DROPDOWN (fonte di verità)
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value.trim() : currentUser;
            
            // Validazione
            if (!requestUser || !currentEmailFile) {
                showToast('Nessuna email selezionata', 'error');
                return;
            }
            
            // Recupera i dettagli dell'email per precompilare il composer
            fetch('get_email_detail.php', {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                // ✅ USA requestUser invece di currentUser
                body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder || currentFolder)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                // ✅ PREPARA DATI COMPLETI PER L'INOLTRO (inclusi allegati)
                const emailData = {
                    from: data.from || 'Sconosciuto',
                    to: data.to || 'Sconosciuto',
                    subject: data.subject || '',
                    date: data.date || new Date().toLocaleString('it-IT'),
                    html_body: data.html_body || '',      // ✅ HTML originale
                    text_body: data.text_body || '',      // ✅ Testo originale
                    attachments: data.attachments || [],  // ✅ Allegati originali
                    // DATI CRITICI PER ALLEGATI - USA requestUser!
                    original_user: requestUser,           // ✅ CORRETTO: non currentUser!
                    original_folder: emailFolder || currentFolder,
                    original_file: currentEmailFile
                };
                
                // VALIDAZIONE CRITICA
                if (!emailData.original_user || !emailData.original_folder || !emailData.original_file) {
                    alert(`❌ Errore: dati email incompleti per l'inoltro.\nUtente: ${emailData.original_user}\nCartella: ${emailData.original_folder}\nFile: ${emailData.original_file}`);
                    return;
                }
                
                // ✅ APRI IL COMPOSER IN MODALITÀ INOLTRO CON DATI COMPLETI
                openComposeModal('forward', emailData);
            })
            .catch(error => {
                showToast('Errore: ' + error.message, 'error');
            });
        }

        // Funzione Salva (IMPLEMENTATA)
        function saveEmail() {
            if (!currentUser || !currentEmailFile) {
                showToast('Nessuna email selezionata', 'error');
                return;
            }
            
            // Mostra dialog di conferma con opzioni
            const format = confirm('Salva email come file .eml?\n\nOK = Formato .eml (completo)\nAnnulla = Formato .txt (solo testo)');
            
            if (format) {
                // Salva come .eml (formato completo)
                saveEmailAsEML();
            } else {
                // Salva come .txt (solo testo)
                saveEmailAsTXT();
            }
        }

        // Salva come file .eml
        function saveEmailAsEML() {
            // ✅ CORREZIONE FONDAMENTALE: Leggi SEMPRE l'utente dal dropdown (non dalla variabile globale)
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;

            fetch('get_email_raw.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder)}`

            })
            .then(response => {
                if (!response.ok) throw new Error('Errore nel download');
                return response.blob();
            })
            .then(blob => {
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                const subject = document.getElementById('detailSubject').textContent || 'email';
                const cleanSubject = subject.replace(/[^\w\s-]/gi, '').substring(0, 50);
                const date = new Date().toISOString().replace(/[:.]/g, '-');
                const filename = `email_${cleanSubject}_${date}.eml`;
                
                a.href = url;
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
                
                showToast('Email salvata come ' + filename, 'success');
            })
            .catch(error => {
                alert('Errore nel salvataggio dell\'email: ' + error.message);
            });
        }

        // Salva come file .txt
        function saveEmailAsTXT() {
            const subject = document.getElementById('detailSubject').textContent || '(nessun oggetto)';
            const from = document.getElementById('detailFrom').textContent || 'Sconosciuto';
            const to = document.getElementById('detailTo').textContent || 'Sconosciuto';
            const date = document.getElementById('detailDate').textContent || 'Data sconosciuta';
            const textBody = document.getElementById('detailBodyText').textContent || 'Nessun contenuto';
            
            const content = `Oggetto: ${subject}\nDa: ${from}\nA: ${to}\nData: ${date}\n\n${'='.repeat(60)}\n\n${textBody}`;
            
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            const cleanSubject = subject.replace(/[^\w\s-]/gi, '').substring(0, 50);
            const dateStr = new Date().toISOString().replace(/[:.]/g, '-');
            const filename = `email_${cleanSubject}_${dateStr}.txt`;
            
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            window.URL.revokeObjectURL(url);
            
            showToast('Email salvata come ' + filename, 'success');
        }

        // Funzione Stampa (IMPLEMENTATA - VERSIONE CORRETTA)
        function printEmail() {
            if (!currentUser || !currentEmailFile) {
                showToast('Nessuna email selezionata', 'error');
                return;
            }
            
            // Crea una finestra di anteprima di stampa
            const printWindow = window.open('', '_blank', 'width=800,height=600');

            // Ottieni i dati dell'email (escape: questi valori tornano da .textContent
            // e vengono re-inseriti come HTML più sotto con document.write, quindi vanno
            // ri-sanificati per evitare injection tramite un oggetto/mittente malevolo)
            const subject = escapeHtml(document.getElementById('detailSubject').textContent || '(nessun oggetto)');
            const from = escapeHtml(document.getElementById('detailFrom').textContent || 'Sconosciuto');
            const to = escapeHtml(document.getElementById('detailTo').textContent || 'Sconosciuto');
            const date = escapeHtml(document.getElementById('detailDate').textContent || 'Data sconosciuta');
            // Il corpo HTML dell'email resta non fidato: non va scritto direttamente in
            // questa finestra (stessa origine dell'app, quindi con pieni privilegi) ma
            // isolato in un iframe sandboxato, come nella vista principale.
            const rawHtmlBody = (currentEmailData && currentEmailData.html_body) || '';

            // Costruisci il contenuto per la stampa - SENZA TAG SCRIPT
            var printContent = '<!DOCTYPE html>';
            printContent += '<html>';
            printContent += '<head>';
            printContent += '<title>Stampa Email</title>';
            printContent += '<style>';
            printContent += 'body { font-family: Arial, sans-serif; margin: 40px; color: #333; }';
            printContent += '.print-header { border-bottom: 2px solid #3b82f6; padding-bottom: 20px; margin-bottom: 30px; }';
            printContent += '.print-header h1 { color: #3b82f6; margin-bottom: 10px; font-size: 24px; }';
            printContent += '.print-meta { display: grid; grid-template-columns: max-content 1fr; gap: 8px 15px; font-size: 14px; }';
            printContent += '.print-meta strong { color: #666; }';
            printContent += '.print-body { line-height: 1.6; }';
            printContent += '.print-footer { margin-top: 50px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #999; }';
            printContent += '#printBodyFrame { width: 100%; border: none; }';
            printContent += '@media print { body { margin: 0; } .no-print { display: none; } }';
            printContent += '</style>';
            printContent += '</head>';
            printContent += '<body>';
            printContent += '<div class="print-header">';
            printContent += '<h1>' + subject + '</h1>';
            printContent += '<div class="print-meta">';
            printContent += '<strong>Da:</strong><span>' + from + '</span>';
            printContent += '<strong>A:</strong><span>' + to + '</span>';
            printContent += '<strong>Data:</strong><span>' + date + '</span>';
            printContent += '</div>';
            printContent += '</div>';
            printContent += '<div class="print-body">';
            // sandbox="allow-same-origin" (senza allow-scripts): il nostro script può
            // leggere l'altezza del contenuto per dimensionare l'iframe prima di stampare,
            // ma nulla dentro l'email può eseguire codice o alterare questa finestra.
            printContent += '<iframe id="printBodyFrame" sandbox="allow-same-origin" referrerpolicy="no-referrer"></iframe>';
            printContent += '</div>';
            printContent += '<div class="print-footer">';
            printContent += '<div class="no-print" style="margin-bottom: 20px;">';
            printContent += '<button onclick="window.print()" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer;">';
            printContent += '🖨️ Stampa';
            printContent += '</button>';
            printContent += '<button onclick="window.close()" style="padding: 10px 20px; background: #6b7280; color: white; border: none; border-radius: 5px; cursor: pointer; margin-left: 10px;">';
            printContent += '✕ Chiudi';
            printContent += '</button>';
            printContent += '</div>';
            printContent += '<p>Email stampata il ' + new Date().toLocaleString('it-IT') + '</p>';
            printContent += '</div>';
            // RIMOSSO COMPLETAMENTE IL TAG <script> - NON NECESSARIO
            printContent += '</body>';
            printContent += '</html>';
            
            printWindow.document.write(printContent);
            printWindow.document.close();

            // Popola l'iframe sandboxato con il corpo email e ridimensiona
            // all'altezza del contenuto (altrimenti la stampa taglierebbe
            // tutto ciò che eccede l'altezza di default dell'iframe)
            const printFrame = printWindow.document.getElementById('printBodyFrame');
            if (printFrame) {
                printFrame.addEventListener('load', function() {
                    try {
                        const h = printFrame.contentWindow.document.documentElement.scrollHeight;
                        printFrame.style.height = h + 'px';
                    } catch (e) {
                        printFrame.style.height = '600px';
                    }
                });
                printFrame.srcdoc = buildEmailIframeDoc(rawHtmlBody || '<p><em>Nessun contenuto disponibile</em></p>');
            }

            // Focus dalla finestra principale (sufficiente)
            printWindow.focus();

            showToast('Finestra di stampa aperta', 'success');
        }

        // Funzione Cancella (IMPLEMENTATA - Sposta nel Trash)
        function deleteEmail() {
            if (!currentUser || !currentEmailFile) {
                showToast('Nessuna email selezionata', 'error');
                return;
            }
            
            // Chiedi conferma
            const confirmDelete = confirm('🗑️ Conferma eliminazione\n\nSei sicuro di voler spostare questa email nel Cestino?\n\nL\'email verrà spostata dalla cartella attuale nella cartella Trash.');
            
            if (!confirmDelete) {
                return;
            }
            
            // Mostra loading
            const loading = document.getElementById('loadingDetail');
            if (loading) {
                loading.style.display = 'flex';
            }
            
            // Esegui lo spostamento

            // ✅ CORREZIONE FONDAMENTALE: Leggi SEMPRE l'utente dal dropdown (non dalla variabile globale)
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;

            fetch('move_to_trash.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder)}`

            })
            .then(response => response.json().catch(() => {
                throw new Error('Errore HTTP: ' + response.status);
            }))
            .then(data => {
                if (loading) {
                    loading.style.display = 'none';
                }
                
                if (data.error) {
                    showToast('Errore: ' + data.error, 'error');
                    return;
                }
                
                if (data.success) {
                    // Mostra messaggio di successo
                    showToast(data.message, 'success');
                    
                    // Rimuovi l'email dalla lista visualizzata
                    const activeEmailItem = document.querySelector('.email-item.active');
                    if (activeEmailItem) {
                        activeEmailItem.remove();
                    }
                    
                    // Nascondi i dettagli email
                    document.getElementById('emailDetail').style.display = 'none';
                    document.getElementById('emptyState').style.display = 'block';
                    
                    // Ricarica la lista email dalla pagina corrente
                    loadEmails(currentPage);
                    
                    // Se non ci sono più email nella pagina, vai alla pagina precedente
                    setTimeout(() => {
                        if (document.querySelectorAll('.email-item').length === 0 && currentPage > 1) {
                            loadEmails(currentPage - 1);
                        }
                    }, 300);
                }
            })
            .catch(error => {
                if (loading) {
                    loading.style.display = 'none';
                }
                alert('❌ Errore durante l\'eliminazione:\n' + error.message);
            });
        }

        // Funzione per recuperare email dal Trash
        function restoreFromTrash() {
            if (!currentUser || !currentEmailFile) {
                showToast('Nessuna email selezionata', 'error');
                return;
            }
            
            if (currentFolder !== 'trash') {
                showToast('Questa email non è nel Cestino', 'error');
                return;
            }
            
            const confirmRestore = confirm('🔄 Conferma ripristino\n\nSei sicuro di voler ripristinare questa email?\n\nL\'email verrà spostata dal Cestino nella cartella Inbox.');
            
            if (!confirmRestore) {
                return;
            }
            
            // Mostra loading
            const loading = document.getElementById('loadingDetail');
            if (loading) {
                loading.style.display = 'flex';
            }
            
            // Esegui il ripristino
            // ✅ CORREZIONE FONDAMENTALE: Leggi SEMPRE l'utente dal dropdown (non dalla variabile globale)
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;

            fetch('restore_from_trash.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder)}`

            })
            .then(response => response.json())
            .then(data => {
                if (loading) {
                    loading.style.display = 'none';
                }
                
                if (data.error) {
                    alert('❌ Errore: ' + data.error);
                    return;
                }
                
                if (data.success) {
                    showToast(data.message, 'success');
                    
                    // Ricarica la lista
                    loadEmails(currentPage);
                    
                    // Nascondi dettagli
                    document.getElementById('emailDetail').style.display = 'none';
                    document.getElementById('emptyState').style.display = 'block';
                }
            })
            .catch(error => {
                if (loading) {
                    loading.style.display = 'none';
                }
                alert('❌ Errore durante il ripristino:\n' + error.message);
            });
        }

        // Funzione per mostrare toast di notifica
        function showToast(message, type = 'info') {
            // Rimuovi toast esistenti
            const existing = document.getElementById('toastNotification');
            if (existing) {
                existing.remove();
            }
            
            // Crea il toast
            const toast = document.createElement('div');
            toast.id = 'toastNotification';
            toast.style.cssText = `
                position: fixed;
                top: 66px;
                right: 20px;
                max-width: calc(100vw - 40px);
                padding: 12px 20px;
                border-radius: 8px;
                color: white;
                font-size: 0.95em;
                font-weight: 500;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
                display: flex;
                align-items: center;
                gap: 10px;
                z-index: 10000;
                animation: slideInToast 0.3s ease, fadeOutToast 0.5s 2.5s ease forwards;
                background: ${type === 'success' ? 'var(--ok)' :
                           type === 'error' ? 'var(--pericolo)' :
                           'var(--accento)'};
            `;
            
            const icon = type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle';
            // Il messaggio può contenere testo delle email (nomi di file, errori del server): sempre come testo
            toast.innerHTML = `<i class="fas ${icon}"></i><span>${escapeHtml(message)}</span>`;
            
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }

        // Aggiungi animazioni CSS per i toast
        const toastStyle = document.createElement('style');
        toastStyle.textContent = `
            @keyframes slideInToast {
                from { transform: translateX(400px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
            
            @keyframes fadeOutToast {
                from { opacity: 1; transform: translateX(0); }
                to { opacity: 0; transform: translateX(200px); }
            }
        `;
        document.head.appendChild(toastStyle);        
                
        // ==================== FUNZIONI DI RICERCA ====================

        // Apri il dialog di ricerca
        function openSearchDialog() {
            // Trasferisci il testo dalla barra di ricerca principale al campo del popup
            const mainSearchInput = document.getElementById('searchInput');
            const popupSearchInput = document.getElementById('searchQuery');
            
            if (mainSearchInput && popupSearchInput) {
                popupSearchInput.value = mainSearchInput.value.trim();
            }
            
            
            // Mostra il dialog
            document.getElementById('searchDialog').style.display = 'flex';
            
            // Focus automatico sul campo di ricerca del popup
            if (popupSearchInput) {
                popupSearchInput.focus();
                // Seleziona tutto il testo per facilitare la modifica
                popupSearchInput.select();
            }
        }

        // Chiudi il dialog di ricerca
        function closeSearchDialog() {
            const mainSearch = document.getElementById('searchInput');
            const popupSearch = document.getElementById('searchQuery');
            
            // Trasferisci il testo indietro se è stato modificato
            if (mainSearch && popupSearch && popupSearch.value.trim() !== '') {
                mainSearch.value = popupSearch.value.trim();
            }
            
            // Nascondi il dialog
            document.getElementById('searchDialog').style.display = 'none';
            
            // Nascondi i risultati
            document.getElementById('searchResults').style.display = 'none';
        }

        // Gestisci pressione tasto Enter nella barra di ricerca
        function handleSearchKeyPress(event) {
            if (event.key === 'Enter') {
                if (event.target.id === 'searchInput') {
                    // Trasferisci il testo e apri il dialog
                    const popupSearchInput = document.getElementById('searchQuery');
                    if (popupSearchInput) {
                        popupSearchInput.value = event.target.value.trim();
                    }
                    openSearchDialog();
                } else if (event.target.id === 'searchQuery') {
                    executeSearch();
                }
            }
        }

        // Esegui la ricerca
        function executeSearch() {
            // Ricerca sul server di posta, in tutta la cartella o in tutte (ricerca.js)
            if (typeof RicercaEmail !== 'undefined') {
                RicercaEmail.cerca();
                return;
            }
            const query = document.getElementById('searchQuery').value.trim();
            
            if (!query) {
                alert('Inserisci un testo da cercare');
                return;
            }
            
            const searchEmail = document.getElementById('searchEmail').checked;
            const searchSubject = document.getElementById('searchSubject').checked;
            const searchBody = document.getElementById('searchBody').checked;
            
            if (!searchEmail && !searchSubject && !searchBody) {
                alert('Seleziona almeno un campo da cercare');
                return;
            }
            
            // Esegui la ricerca
            const results = performSearch(query, searchEmail, searchSubject, searchBody);
            
            // Mostra i risultati
            displaySearchResults(results, query);
        }

        // Esegui la ricerca nelle email correnti
        function performSearch(query, searchEmail, searchSubject, searchBody) {
            const results = [];
            const searchTerm = query.toLowerCase();
            
            currentEmails.forEach(email => {
                let matches = [];
                let matched = false;
                
                // Cerca in email (mittente/destinatario)
                if (searchEmail) {
                    if (email.sender && email.sender.toLowerCase().includes(searchTerm)) {
                        matches.push('email');
                        matched = true;
                    }
                    if (email.recipient && email.recipient.toLowerCase().includes(searchTerm)) {
                        matches.push('email');
                        matched = true;
                    }
                }
                
                // Cerca in oggetto
                if (searchSubject && email.subject && email.subject.toLowerCase().includes(searchTerm)) {
                    matches.push('oggetto');
                    matched = true;
                }
                
                // Cerca nel corpo (se disponibile)
                if (searchBody && email.text_body && email.text_body.toLowerCase().includes(searchTerm)) {
                    matches.push('corpo');
                    matched = true;
                }
                
                if (matched) {
                    results.push({
                        ...email,
                        matches: matches
                    });
                }
            });
            
            return results;
        }

        /**
         * Visualizza risultati ricerca
         * CORRETTO: Preserva la selezione utente
         */
        function displaySearchResults(results, searchTerm) {
            const emailList = document.getElementById('emailList');
            const emailCount = document.getElementById('emailCount');
            const contentTitle = document.getElementById('contentTitle');
            
            if (!emailList || !emailCount || !contentTitle) {
                return;
            }
            
            // ✅ Determina cartella attiva DAL BOTTONE (fonte di verità)
            let activeFolderBtn = document.querySelector('.folder-btn.active');
            let activeFolder = activeFolderBtn 
                ? activeFolderBtn.getAttribute('data-folder') 
                : 'inbox';
            
            protectUserSelectContainer();
            
            if (results.length === 0) {
                emailList.innerHTML = `
                    <div class="no-emails">
                        <i class="fas fa-search"></i>
                        <p>Nessun risultato trovato per: <strong>"${escapeHtml(searchTerm)}"</strong></p>
                        <button onclick="clearSearch()" class="retry-btn">
                            <i class="fas fa-times"></i> Annulla ricerca
                        </button>
                    </div>
                `;
                emailCount.textContent = '0 risultati';
                contentTitle.textContent = 'Ricerca: Nessun risultato';
            } else {
                // ✅ GENERA HTML SENZA onclick inline (usa data attributes)
                let html = '';
                results.forEach((email, index) => {
                    const isActive = false;
                    const displaySender = email.sender || 'Sconosciuto';
                    const displaySubject = email.subject || '(nessun oggetto)';
                    
                    html += `
                        <div class="email-item ${isActive ? 'active' : ''}"
                            data-file="${escapeHtmlAttr(email.file)}"
                            data-folder="${escapeHtmlAttr(activeFolder)}">
                            <div class="email-item-header">
                                <div class="email-sender-container">
                                    <div class="email-sender-text" title="${escapeHtmlAttr(displaySender)}">
                                        ${escapeHtml(displaySender)}
                                    </div>
                                </div>
                                <div class="email-date">${escapeHtml(email.date || 'Data sconosciuta')}</div>
                            </div>
                            <div class="email-item-body">
                                <div class="email-subject-preview" title="${escapeHtmlAttr(displaySubject)}">
                                    ${highlightSearchTerm(escapeHtml(displaySubject), searchTerm)}
                                </div>
                            </div>
                        </div>
                    `;
                });
                
                // ✅ Inserisci HTML
                emailList.innerHTML = html;
                
                // ✅ AGGIUNGI EVENT LISTENERS DOPO AVER INSERITO L'HTML
                emailList.querySelectorAll('.email-item').forEach(item => {
                    item.addEventListener('click', function(e) {
                        e.preventDefault();
                        const file = this.getAttribute('data-file');
                        showEmailDetails(file, this);
                    });
                });
                
                emailCount.textContent = `${results.length} risultati per: "${searchTerm}"`;
                contentTitle.textContent = `Ricerca: ${results.length} risultati`;
            }
            
            // ✅ INSERISCI QUESTO AL POSTO DEL PRECEDENTE
            setTimeout(() => {
                protectUserSelectContainer();
                const container = document.getElementById('userSelectContainer');
                if (container && getComputedStyle(container).display === 'none') {
                    recreateUserSelectContainer();
                }
            }, 150);
        }

        /**
         * Annulla ricerca e ripristina lista email originale
         */
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            
            // ✅ CRITICO: Forza visibilità user-select-container
            const userSelectContainer = document.querySelector('.user-select-container');
            if (userSelectContainer) {
                userSelectContainer.style.display = 'block';
                userSelectContainer.style.opacity = '1';
            }
            
            // Ricarica lista email corrente
            loadEmails(currentPage);
            
        }

        // Aggiorna currentEmails quando si caricano le email
        function updateCurrentEmails(emails) {
            currentEmails = emails;
        }      
        
        // Ricerca rapida dalla barra principale
        function quickSearch() {
            const searchInput = document.getElementById('searchInput');
            const query = searchInput.value.trim();
            
            if (!query) {
                searchInput.focus();
                return;
            }
            
            // Apri il dialog e trasferisci il testo
            const popupSearchInput = document.getElementById('searchQuery');
            if (popupSearchInput) {
                popupSearchInput.value = query;
            }
            
            openSearchDialog();
            
            // Esegui automaticamente la ricerca con le opzioni di default
            setTimeout(() => {
                executeSearch();
            }, 300);
        }
        
        // Aggiungi questa funzione per aggiornare i bottoni in base alla cartella
        function updateActionButtons() {
            const deleteBtn = document.querySelector('.delete-btn');
            const restoreBtn = document.querySelector('.restore-btn');
            
            if (currentFolder === 'trash') {
                // Mostra bottone Ripristina, nascondi Cancella
                if (deleteBtn) deleteBtn.style.display = 'none';
                if (restoreBtn) restoreBtn.style.display = 'flex';
            } else {
                // Mostra bottone Cancella, nascondi Ripristina
                if (deleteBtn) deleteBtn.style.display = 'flex';
                if (restoreBtn) restoreBtn.style.display = 'none';
            }
        }

        // Sincronizza i campi di ricerca
        function syncSearchFields() {
            const mainSearch = document.getElementById('searchInput');
            const popupSearch = document.getElementById('searchQuery');
            
            if (!mainSearch || !popupSearch) return;
            
            // Quando il popup si chiude, trasferisci il testo indietro
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'style') {
                        const dialog = document.getElementById('searchDialog');
                        if (dialog && dialog.style.display === 'none') {
                            // Dialog chiuso, sincronizza indietro
                            if (popupSearch.value.trim() !== '') {
                                mainSearch.value = popupSearch.value.trim();
                            }
                        }
                    }
                });
            });
            
            // Osserva i cambiamenti nello stile del dialog
            const dialog = document.getElementById('searchDialog');
            if (dialog) {
                observer.observe(dialog, {
                    attributes: true,
                    attributeFilter: ['style']
                });
            }
        }
        
        // ==================== FUNZIONI PER ALLEGATI ====================

        // Verifica se un file è previewable (PDF o immagine)
        function isPreviewable(extension) {
            const previewable = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'];
            return previewable.includes(extension.toLowerCase());
        }

        // Ottieni l'estensione del file
        function getFileExtension(filename) {
            return filename.split('.').pop().toLowerCase();
        }

        // Preview di un allegato
        function previewAttachment(index, folderOverride = null) {
            // ✅ Determina la cartella CORRETTA per l'allegato
            const attachmentFolder = folderOverride || emailFolder;
            
            // Validazione input critica
            if (!currentUser || !currentEmailFile) {
                showToast('Nessuna email selezionata', 'error');
                return;
            }
            
            if (!attachmentFolder) {
                showToast('Cartella email non disponibile per la preview', 'error');
                return;
            }
            
            // ✅ CORREZIONE: Usa la cache currentEmailData invece di riscaricare l'email
            // Questo evita: (1) doppia chiamata di rete, (2) possibile disallineamento indici allegati
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;

            // Funzione interna che esegue il preview usando i dati già disponibili
            function doPreviewWithData(data) {
                if (!data.attachments || !Array.isArray(data.attachments) || index >= data.attachments.length) {
                    throw new Error(`Allegato non trovato alla posizione ${index} (totali: ${data.attachments?.length || 0})`);
                }
                
                const attachment = data.attachments[index];
                
                // Determina tipo file per preview
                const fileExt = getFileExtension(attachment.filename).toLowerCase();
                const isImage = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg'].includes(fileExt);
                const isPdf = fileExt === 'pdf';
                
                // Apri anteprima in base al tipo
                if (isImage) {
                    // ✅ Passa TUTTI i parametri necessari per il download dell'immagine
                    previewImage(
                        attachment, 
                        requestUser, 
                        currentEmailFile, 
                        attachmentFolder, 
                        index
                    );
                } else if (isPdf) {
                    // ✅ Passa TUTTI i parametri necessari per il download del PDF
                    previewPdf(
                        attachment, 
                        requestUser, 
                        currentEmailFile, 
                        attachmentFolder, 
                        index
                    );
                } else {
                    showToast(`Anteprima non disponibile per: .${fileExt}`, 'info');
                }
            } // fine doPreviewWithData

            // ✅ LOGICA CACHE-FIRST: usa i dati già in memoria, con fallback al fetch
            try {
                if (currentEmailData && currentEmailData.attachments &&
                    currentEmailData.attachments.length > index) {
                    // Dati già disponibili: nessuna chiamata di rete
                    doPreviewWithData(currentEmailData);
                } else {
                    // Fallback: scarica l'email se la cache è vuota o insufficiente
                    fetch('get_email_detail.php', {
                        method: 'POST',
                        credentials: 'include',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(attachmentFolder)}`
                    })
                    .then(response => {
                        if (!response.ok) return response.text().then(t => { throw new Error(`HTTP ${response.status}: ${t.substring(0,200)}`); });
                        return response.json();
                    })
                    .then(data => {
                        if (data.error) throw new Error(data.error);
                        currentEmailData = data; // Aggiorna la cache
                        doPreviewWithData(data);
                    })
                    .catch(error => {
                        showToast(`Errore preview: ${error.message || 'Errore sconosciuto'}`, 'error');
                    });
                }
            } catch(error) {
                showToast(`Errore preview: ${error.message || 'Errore sconosciuto'}`, 'error');
            }
        }
        // Preview immagine
        function previewImage(attachment, user, file, folder, index) {
            // Validazione parametri
            if (!attachment || !user || !file || !folder) {
                showToast('Errore: Dati allegato non validi', 'error');
                return;
            }
            
            // ✅ Costruisci URL CORRETTO con tutti i parametri necessari
            const imageUrl = `get_attachment_content.php?user=${encodeURIComponent(user)}&file=${encodeURIComponent(file)}&index=${encodeURIComponent(index)}&folder=${encodeURIComponent(folder)}&disposition=inline`;
            
            // Apri modal preview immagine
            const previewModal = document.getElementById('previewModal');
            const previewContent = document.getElementById('previewContent');
            const previewTitle = document.getElementById('previewTitle');
            const previewCloseBtn = document.getElementById('previewCloseBtn');
            
            if (!previewModal || !previewContent || !previewTitle) {
                showToast('Errore: Modal preview non disponibile', 'error');
                return;
            }
            
            // Imposta titolo
            previewTitle.textContent = `Anteprima: ${attachment.filename}`;
            
            // Crea contenuto immagine
            previewContent.innerHTML = `
                <div class="image-preview-container">
                    <img 
                        src="${escapeHtmlAttr(imageUrl)}" 
                        alt="Anteprima: ${escapeHtmlAttr(attachment.filename)}" 
                        class="preview-image"
                        loading="lazy"
                        onerror="this.style.display='none'; this.parentElement.innerHTML='<p style=\'color:#ef4444; text-align:center;\'>❌ Immagine non caricabile</p>'">
                    <div class="image-actions">
                        <button type="button" class="btn-preview-action" 
                            onclick="downloadAttachmentViaFetch('${encJs(user)}', '${encJs(file)}', '${encJs(index)}', '${encJs(folder)}', decodeURIComponent('${encJs(attachment.filename)}'))"
                            title="Scarica ${escapeHtmlAttr(attachment.filename)}">
                            <i class="fas fa-download"></i> Scarica
                        </button>
                    </div>
                </div>
            `;
            
            // Mostra modal
            previewModal.style.display = 'flex';
            
            // Chiudi modal al click su close
            if (previewCloseBtn) {
                previewCloseBtn.onclick = function() {
                    previewModal.style.display = 'none';
                    previewContent.innerHTML = '';
                };
            }
            
            // Chiudi modal al click fuori dal contenuto
            previewModal.onclick = function(e) {
                if (e.target === previewModal) {
                    previewModal.style.display = 'none';
                    previewContent.innerHTML = '';
                }
            };
            
        }
        // Preview PDF
        function previewPdf(attachment, user, file, folder, index) {
            // Validazione parametri
            if (!attachment || !user || !file || !folder) {
                showToast('Errore: Dati allegato non validi', 'error');
                return;
            }
            
            // ✅ Costruisci URL CORRETTO con tutti i parametri necessari
            const pdfUrl = `get_attachment_content.php?user=${encodeURIComponent(user)}&file=${encodeURIComponent(file)}&index=${encodeURIComponent(index)}&folder=${encodeURIComponent(folder)}&disposition=inline`;
            
            // Apri modal preview PDF
            const previewModal = document.getElementById('previewModal');
            const previewContent = document.getElementById('previewContent');
            const previewTitle = document.getElementById('previewTitle');
            const previewCloseBtn = document.getElementById('previewCloseBtn');
            
            if (!previewModal || !previewContent || !previewTitle) {
                showToast('Errore: Modal preview non disponibile', 'error');
                return;
            }
            
            // Imposta titolo
            previewTitle.textContent = `Anteprima: ${attachment.filename}`;
            
            // Crea iframe per PDF
            previewContent.innerHTML = `
                <div class="pdf-preview-container">
                    <iframe 
                        src="${escapeHtmlAttr(pdfUrl)}" 
                        class="pdf-iframe" 
                        title="Anteprima PDF: ${escapeHtmlAttr(attachment.filename)}"
                        allow="fullscreen"
                        loading="lazy">
                    </iframe>
                    <div class="pdf-actions">
                        <a href="${escapeHtmlAttr(pdfUrl)}" target="_blank" rel="noopener" class="btn-preview-action" title="Apri in nuova finestra">
                            <i class="fas fa-expand"></i> Apri a schermo intero
                        </a>
                        <button type="button" class="btn-preview-action" 
                            onclick="downloadAttachmentViaFetch('${encJs(user)}', '${encJs(file)}', '${encJs(index)}', '${encJs(folder)}', decodeURIComponent('${encJs(attachment.filename)}'))"
                            title="Scarica ${escapeHtmlAttr(attachment.filename)}">
                            <i class="fas fa-download"></i> Scarica
                        </button>
                    </div>
                </div>
            `;
            
            // Mostra modal
            previewModal.style.display = 'flex';
            
            // Chiudi modal al click su close
            if (previewCloseBtn) {
                previewCloseBtn.onclick = function() {
                    previewModal.style.display = 'none';
                    previewContent.innerHTML = '';
                };
            }
            
            // Chiudi modal al click fuori dal contenuto
            previewModal.onclick = function(e) {
                if (e.target === previewModal) {
                    previewModal.style.display = 'none';
                    previewContent.innerHTML = '';
                }
            };
            
        }
        
        // ==================== COMPOSE EMAIL FUNCTIONS ====================

        // Apri modal composizione
        // Aggiorna openComposeModal per gestire l'inoltro completo
        /** Riga "Da" della finestra di scrittura: la casella scelta nel menu Account */
        function impostaRigaDa() {
            const daEl = document.getElementById('composeDa');
            const select = document.getElementById('userSelect');
            const opzione = select && select.selectedOptions[0];
            if (daEl) daEl.textContent = (opzione && opzione.value) ? opzione.textContent : '';
        }

        function openComposeModal(mode = 'new', emailData = null) {
            // Resetta array allegati all'apertura
            selectedFiles = [];
            forwardedAttachmentsList = [];

            // Reset form
            const form = document.getElementById('composeForm');
            if (form) form.reset();
            
            const attachmentPreview = document.getElementById('attachmentPreview');
            if (attachmentPreview) attachmentPreview.innerHTML = '';
            
/*
            // Reset format buttons
            document.querySelectorAll('.format-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector('.format-btn:first-child').classList.add('active');
            if (document.getElementById('composeIsHtml')) {
                document.getElementById('composeIsHtml').value = '0';
            }
*/            

            // ✅ DOPO (imposta HTML come predefinito)
            document.querySelectorAll('.format-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            // Attiva il pulsante HTML (cerca per testo per maggiore affidabilità)
            const htmlBtn = Array.from(document.querySelectorAll('.format-btn')).find(btn => 
                btn.textContent?.toLowerCase().includes('html')
            );
            if (htmlBtn) {
                htmlBtn.classList.add('active');
            } else {
                // Fallback: attiva il secondo pulsante (se HTML è il secondo)
                const formatBtns = document.querySelectorAll('.format-btn');
                if (formatBtns.length > 1) formatBtns[1].classList.add('active');
            }
            // Imposta valore HTML nel campo hidden
            const isHtmlInput = document.getElementById('composeIsHtml');
            if (isHtmlInput) {
                isHtmlInput.value = '1'; // ← HTML ATTIVATO
            }

            // Nascondi warning inoltra se esiste
            const forwardWarning = document.getElementById('forwardWarning');
            if (forwardWarning) forwardWarning.style.display = 'none';
            
            // Imposta mittente (usa l'utente corrente al momento dell'apertura)
            // Leggi l'utente dal dropdown se disponibile, altrimenti dalla variabile globale
            const userSelect = document.getElementById('userSelect');
            const senderUser = (userSelect && userSelect.value.trim()) ? userSelect.value.trim() : currentUser;
            const userEmail = senderUser.includes('@') ? senderUser : `${senderUser}@yourdomain.com`;
            const fromEmailField    = document.getElementById('composeFromEmail');
            const fromNameField     = document.getElementById('composeFromName');
            const accountIdField    = document.getElementById('composeAccountId');

            if (fromEmailField)  fromEmailField.value  = userEmail;
            if (fromNameField)   fromNameField.value   = senderUser;
            // Blocca l'account mittente nel form: anche se l'utente cambia dropdown,
            // l'invio userà sempre l'account scelto a questo momento.
            if (accountIdField) accountIdField.value = senderUser;
            impostaRigaDa();
            
            // Configura modal in base alla modalità
            const titleEl = document.getElementById('composeModalTitle');
            const toField = document.getElementById('composeTo');
            const subjectField = document.getElementById('composeSubject');
            const bodyField = document.getElementById('composeBody');
            const forwardOriginalField = document.getElementById('forwardOriginalEmail');
            
            if (!titleEl || !subjectField || !bodyField) {
                return;
            }
            
            if (mode === 'forward' && emailData) {
                
                // Titolo modal
                titleEl.innerHTML = '<i class="fas fa-share"></i> Inoltra Email';
                toField.value = '';
                
                // Oggetto con "Fwd:"
                let subject = emailData.subject || '';
                if (!subject.toLowerCase().startsWith('fwd:')) {
                    subject = 'Fwd: ' + subject;
                }
                subjectField.value = subject;
                
                // ✅ DETERMINA FORMATO INOLTRO (HTML se disponibile, altrimenti testo)
                const isHtmlForward = (emailData.html_body && emailData.html_body.trim().length > 0) || 
                                    (document.getElementById('composeIsHtml')?.value === '1');
                
                // ✅ FORMATTAZIONE INOLTRO CON SEPARATORE STANDARD
                let forwardBody = '';
                const separatorLine = "------------------------ Forwarded message ------------------------";
                
                if (isHtmlForward) {
                    // ✅ INOLTRO HTML CON FORMATTAZIONE PROFESSIONALE
                    const now = new Date();
                    const formattedDate = now.toLocaleString('it-IT', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    // Pulisci HTML per sicurezza
                    const cleanHtmlBody = pulisciHtmlEmail(emailData.html_body || '');
                    
                    // ✅ CORPO INOLTRO HTML CON SEPARATORE E METADATI
                    forwardBody = `
            <div style="font-family: Arial, sans-serif; font-size: 13px; color: #5f6368; margin: 12px 0;">
                <div style="font-weight: bold; margin-bottom: 4px;"><br><br>===================================<br>Da:</div>
                <div style="margin-bottom: 8px;">${escapeHtml(emailData.from || 'Sconosciuto')}</div>
                
                <div style="font-weight: bold; margin-bottom: 4px;">Data:</div>
                <div style="margin-bottom: 8px; color: #808080;">${escapeHtml(emailData.date || formattedDate)}</div>
                
                <div style="font-weight: bold; margin-bottom: 4px;">Oggetto:</div>
                <div style="margin-bottom: 8px;">${escapeHtml(emailData.subject || '(nessun oggetto)')}</div>
                
                <div style="font-weight: bold; margin-bottom: 4px;">A:</div>
                <div style="margin-bottom: 16px;">${escapeHtml(emailData.to || 'Sconosciuto')}</div>
                
                <div style="border-top: 1px solid #e0e0e0; padding-top: 12px; margin-top: 8px;">
                    ${cleanHtmlBody || '<p style="margin: 0; color: #5f6368;">(nessun contenuto)</p>'}
                </div>
            </div>
                    `.trim();
                } else {
                    // ✅ INOLTRO TESTO SEMPLICE CON SEPARATORE STANDARD
                    const now = new Date();
                    const formattedDate = now.toLocaleString('it-IT', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit'
                    });
                    
                    // Formatta corpo testo originale
                    const originalText = emailData.text_body || '(nessun contenuto)';
                    
                    // ✅ CORPO INOLTRO TESTO CON SEPARATORE
                    forwardBody = `
            ${separatorLine}
            Da: ${emailData.from || 'Sconosciuto'}
            Data: ${emailData.date || formattedDate}
            Oggetto: ${emailData.subject || '(nessun oggetto)'}
            A: ${emailData.to || 'Sconosciuto'}

            ${originalText}
                    `.trim();
                }
                
                // ✅ IMPOSTA FORMATO CORRETTO (HTML o testo)
                const isHtmlInput = document.getElementById('composeIsHtml');
                if (isHtmlInput) {
                    isHtmlInput.value = isHtmlForward ? '1' : '0';
                }
                
                // ✅ GESTIONE EDITOR HTML vs TEXTAREA
                const emailEditor = document.getElementById('emailEditor');
                const bodyTextarea = document.getElementById('composeBody');
                
                if (isHtmlForward && emailEditor) {
                    // Modalità HTML: usa l'editor contenteditable
                    emailEditor.innerHTML = forwardBody;
                    if (bodyTextarea) bodyTextarea.value = '';
                    
                    // Mostra editor HTML, nascondi textarea
                    emailEditor.style.display = 'block';
                    if (bodyTextarea) bodyTextarea.style.display = 'none';
                } else if (bodyTextarea) {
                    // Modalità testo: usa la textarea
                    bodyTextarea.value = forwardBody;
                    
                    // Mostra textarea, nascondi editor HTML
                    bodyTextarea.style.display = 'block';
                    if (emailEditor) emailEditor.style.display = 'none';
                }
                
                // ✅ VALORIZZA CAMPO HIDDEN PER ALLEGATI ORIGINALI
                const forwardRef = `${emailData.original_user}:${emailData.original_folder}:${emailData.original_file}`;
                const forwardField = document.getElementById('forwardOriginalEmail');
                if (forwardField) {
                    forwardField.value = forwardRef;
                } else {
                    alert('Errore interno: impossibile preparare l\'inoltro con allegati. Contatta l\'amministratore.');
                    return;
                }
                
                // Popola la lista allegati inoltrati: ogni elemento ricorda il proprio
                // indice nell'email originale, così al momento dell'invio il server
                // saprà esattamente quali includere.
                forwardedAttachmentsList = (emailData.attachments || []).map((att, idx) => ({
                    index: idx,
                    filename: att.filename || `allegato_${idx}`,
                    size: att.size || 0,
                    content_type: att.content_type || 'application/octet-stream'
                }));
                updateAttachmentPreview();

                // Nascondi il vecchio warning statico (sostituito dalla preview)
                const forwardWarning = document.getElementById('forwardWarning');
                if (forwardWarning) forwardWarning.style.display = 'none';

                // Focus sul campo destinatario
                setTimeout(() => {
                    if (toField) toField.focus();
                }, 300);
                
            } else if (mode === 'reply' && emailData) {
                titleEl.innerHTML = '<i class="fas fa-reply"></i> Rispondi';
                toField.value = extractEmail(emailData.from) || '';
                
                // ✅ CORREZIONE FONDAMENTALE: Resetta SEMPRE il campo forward_original_email in modalità reply
                if (forwardOriginalField) forwardOriginalField.value = ''; // ← AGGIUNGI QUESTA RIGA

                let subject = emailData.subject || '';
                if (!subject.toLowerCase().startsWith('re:')) {
                    subject = 'Re: ' + subject;
                }
                subjectField.value = subject;
                
                // ✅ SEPARATORE STANDARD PER CITAZIONE
                const separatorLine = "==============================================";

                const now = new Date();
                const formattedDate = now.toLocaleString('it-IT', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
                
                const quotedHeader = `Il ${formattedDate}, ${emailData.from || 'Sconosciuto'} ha scritto:`;
                const quotedBody = emailData.text_body ? 
                    emailData.text_body.split('\n').map(line => `> ${line}`).join('\n') : 
                    '';
                
                const replyBody = `\n\n\n${quotedHeader}\n${quotedBody}`;
                bodyField.value = replyBody;
                
                if (forwardWarning) forwardWarning.style.display = 'none';
                
                setTimeout(() => {
                    bodyField.focus();
                    bodyField.setSelectionRange(0, 0);
                }, 300);
                
            } else {
                titleEl.innerHTML = '<i class="fas fa-edit"></i> Nuova email';
                if (forwardOriginalField) forwardOriginalField.value = '';
                if (forwardWarning) forwardWarning.style.display = 'none';
            }
            
            // Bozze: il contenuto iniziale (citazione, inoltro) non conta come modifica
            if (typeof BozzeEmail !== 'undefined') BozzeEmail.inizia();

            // Mostra modal
            const modal = document.getElementById('composeModal');
            if (modal) {
                modal.style.display = 'flex';
                
                setTimeout(() => {
                    if (mode === 'forward') {
                        subjectField.focus();
                    } else {
                        toField.focus();
                    }
                }, 300);
            }
        }

        // Chiudi modal
        function closeComposeModal() {
            const overlay = document.getElementById('composeModal');
            if (!overlay) return;
            // Modifiche non salvate: bozze.js chiede se salvarle (e chiude dopo)
            if (typeof BozzeEmail !== 'undefined' && !BozzeEmail.puoChiudere()) return;
            const modal = overlay.querySelector('.compose-modal');
            const body  = modal ? modal.querySelector('.compose-modal-body') : null;

            // Pulisce lo stato minimizzato se presente
            if (modal && modal.dataset.minimized === '1') {
                modal.dataset.minimized = '0';
                overlay.style.background = overlay.style.pointerEvents =
                overlay.style.alignItems = overlay.style.justifyContent =
                overlay.style.padding    = '';
                modal.style.width = modal.style.maxWidth = modal.style.maxHeight =
                modal.style.borderRadius = modal.style.cursor =
                modal.style.boxShadow    = modal.style.pointerEvents = '';
                if (body) body.style.display = '';
            }

            clearAttachments();

            if (typeof selectedFiles !== 'undefined') {
                selectedFiles = [];
            }
            forwardedAttachmentsList = [];

            overlay.style.display = 'none';
        }

        // Minimizza / ripristina il modal compose
        function toggleMinimizeCompose() {
            const overlay = document.getElementById('composeModal');
            if (!overlay) return;
            const modal = overlay.querySelector('.compose-modal');
            if (!modal)  return;
            const body  = modal.querySelector('.compose-modal-body');
            const btn   = document.getElementById('btnMinimizeCompose');
            const icon  = btn ? btn.querySelector('i') : null;

            const isMinimized = modal.dataset.minimized === '1';

            if (isMinimized) {
                // ── Ripristina ──────────────────────────────────────────────
                modal.dataset.minimized = '0';
                // overlay: torna schermo pieno con sfondo scuro
                overlay.style.background     = '';
                overlay.style.pointerEvents  = '';
                overlay.style.alignItems     = '';
                overlay.style.justifyContent = '';
                overlay.style.padding        = '';
                // modal: torna dimensioni normali
                modal.style.width            = '';
                modal.style.maxWidth         = '';
                modal.style.maxHeight        = '';
                modal.style.borderRadius     = '';
                modal.style.cursor           = '';
                modal.style.boxShadow        = '';
                // rimostra il corpo
                if (body) body.style.display = '';
                // aggiorna icona e tooltip
                if (icon) icon.className = 'fas fa-minus';
                if (btn)  btn.title = 'Minimizza';
            } else {
                // ── Minimizza ────────────────────────────────────────────────
                modal.dataset.minimized = '1';
                // overlay: trasparente, non blocca i click
                overlay.style.background     = 'transparent';
                overlay.style.pointerEvents  = 'none';
                overlay.style.alignItems     = 'flex-end';
                overlay.style.justifyContent = 'flex-end';
                overlay.style.padding        = '0 24px 0 0';
                // modal: solo la barra dell'header in basso a destra
                modal.style.width            = '320px';
                modal.style.maxWidth         = '320px';
                modal.style.maxHeight        = 'none';
                modal.style.borderRadius     = '12px 12px 0 0';
                modal.style.cursor           = 'pointer';
                modal.style.boxShadow        = '0 -4px 20px rgba(0,0,0,0.35)';
                modal.style.pointerEvents    = 'all';
                // nasconde il corpo (form)
                if (body) body.style.display = 'none';
                // aggiorna icona e tooltip
                if (icon) icon.className = 'fas fa-window-restore';
                if (btn)  btn.title = 'Ripristina';
            }
        }

        // Ripristina solo se il modal è minimizzato (chiamato dal click sull'header)
        function restoreComposeModal() {
            const overlay = document.getElementById('composeModal');
            const modal   = overlay ? overlay.querySelector('.compose-modal') : null;
            if (modal && modal.dataset.minimized === '1') {
                toggleMinimizeCompose();
            }
        }

        // Toggle formato testo/HTML
        function toggleFormat(format) {
            document.querySelectorAll('.format-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            if (format === 'text') {
                document.querySelector('.format-btn:first-child').classList.add('active');
                document.getElementById('composeIsHtml').value = '0';
            } else {
                document.querySelector('.format-btn:last-child').classList.add('active');
                document.getElementById('composeIsHtml').value = '1';
            }
        }

        // Anteprima allegati
        document.getElementById('composeAttachments').addEventListener('change', function(e) {
            const preview = document.getElementById('attachmentPreview');
            
            // ✅ ACCUMULA i nuovi file invece di sovrascrivere
            if (this.files.length > 0) {
                // Aggiungi i nuovi file all'array esistente
                Array.from(this.files).forEach(file => {
                    // Evita duplicati (stesso nome e dimensione)
                    const exists = selectedFiles.some(f => 
                        f.name === file.name && f.size === file.size
                    );
                    if (!exists) {
                        selectedFiles.push(file);
                    }
                });
                
                // Aggiorna la preview con TUTTI i file accumulati
                updateAttachmentPreview();
                
            }
        });

        /**
         * Aggiorna la preview degli allegati
         */
        function updateAttachmentPreview() {
            const preview = document.getElementById('attachmentPreview');
            preview.innerHTML = '';

            // Allegati manuali (upload dell'utente)
            selectedFiles.forEach((file, index) => {
                const div = document.createElement('div');
                div.className = 'attachment-item';
                div.innerHTML = `
                    <i class="fas fa-paperclip"></i>
                    <span>${escapeHtml(file.name)} (${formatFileSize(file.size)})</span>
                    <button type="button" class="remove-attachment" data-index="${index}" onclick="removeAttachment(this)">×</button>
                `;
                preview.appendChild(div);
            });

            // Allegati inoltrati (dall'email originale, rimovibili singolarmente)
            forwardedAttachmentsList.forEach((att) => {
                const div = document.createElement('div');
                div.className = 'attachment-item attachment-item-forwarded';
                div.innerHTML = `
                    <i class="fas fa-share" style="color:#667eea;"></i>
                    <span>${escapeHtml(att.filename)} (${formatFileSize(att.size)})</span>
                    <span class="attachment-badge-fwd">${escapeHtml(att.badge || 'inoltrato')}</span>
                    <button type="button" class="remove-attachment" onclick="removeForwardedAttachment(${att.index})">×</button>
                `;
                preview.appendChild(div);
            });

            // Contatore totale
            const attachmentsCount = document.getElementById('attachmentsCount');
            if (attachmentsCount) {
                attachmentsCount.textContent = selectedFiles.length + forwardedAttachmentsList.length;
            }
        }

        /**
         * Rimuovi un allegato dall'array
         */
        function removeAttachment(btn, filename) {
            // Usa l'indice invece del nome per evitare ambiguità
            const index = parseInt(btn.getAttribute('data-index'));
            if (index >= 0 && index < selectedFiles.length) {
                selectedFiles.splice(index, 1);
                updateAttachmentPreview();
            }
        }

        /**
         * Rimuove un allegato inoltrato dalla lista usando il suo indice originale
         */
        function removeForwardedAttachment(originalIndex) {
            forwardedAttachmentsList = forwardedAttachmentsList.filter(att => att.index !== originalIndex);
            updateAttachmentPreview();
        }

        /**
         * Invio email - VERSIONE CORRETTA E DEFINITIVA
         * Correzioni applicate:
         * ✅ RIMOSSA clearAttachments() all'inizio (non svuota il form prima di leggere i dati)
         * ✅ Legge i dati PRIMA di qualsiasi modifica al form
         * ✅ Pulisce SOLO DOPO invio riuscito
         * ✅ Pulisce correttamente editor HTML contenteditable
         */
        function sendEmail() {
            const form = document.getElementById('composeForm');
            if (!form) {
                alert('Errore: Form di composizione non disponibile');
                return;
            }
            
            // ✅ LEGGI I DATI PRIMA DI QUALSIASI MODIFICA AL FORM
            const formData = new FormData(form);
            
            // Allegati manuali accumulati
            if (selectedFiles.length > 0) {
                selectedFiles.forEach((file, index) => {
                    formData.append('attachments[]', file);
                });
            }

            // Allegati inoltrati: passa gli indici da includere.
            // Il server userà questi indici per filtrare l'email originale.
            // Stringa vuota = l'utente ha rimosso tutti gli allegati.
            const forwardField = document.getElementById('forwardOriginalEmail');
            if (forwardField && forwardField.value.trim() !== '') {
                const indices = forwardedAttachmentsList.map(att => att.index).join(',');
                formData.set('forward_attachment_indices', indices);
            }

            // L'account mittente è già nel FormData dal campo nascosto #composeAccountId
            // (impostato all'apertura del modal) — non lo sovrascriviamo col dropdown,
            // così l'invio usa sempre il mittente originale anche se l'utente ha
            // cambiato selezione nel frattempo (es. dopo aver minimizzato il compose).
            const requestUser = formData.get('account_id') || currentUser;
            
            // ✅ VALIDAZIONE CAMPI (usa SOLO FormData, non elementi DOM)
            const to = formData.get('to')?.trim();
            const subject = formData.get('subject')?.trim();
            let body = formData.get('body')?.trim();
            
            // ✅ DEBUG ESPPLICITO: Verifica immediata dell'oggetto
            // Validazione esplicita con messaggi contestuali
            if (!to) {
                alert('⚠️ Compila il campo "A:" (destinatario)');
                return;
            }
            
            if (!to.includes('@')) {
                alert('⚠️ Indirizzo email destinatario non valido (mancato simbolo @)');
                return;
            }
            
            if (!subject) {
                alert('⚠️ Compila il campo "Oggetto"');
                return;
            }
            
            if (!body || body === '') {
                // Fallback: recupera corpo dall'editor contenteditable se esiste
                const emailEditor = document.getElementById('emailEditor');
                if (emailEditor) {
                    body = emailEditor.innerHTML.trim();
                    formData.set('body', body);
                }
                if (!body || body === '') {
                    alert('⚠️ Compila il campo "Messaggio" (corpo email vuoto)');
                    return;
                }
            }
            
            // Disabilita pulsante durante invio
            const sendBtn = document.querySelector('.btnsend');
            if (!sendBtn) {
                return;
            }
            
            const originalText = sendBtn.innerHTML;
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Invio in corso...';
            
            // ✅ INVIO (FormData contiene TUTTI i dati originali)
            fetch('send_email.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    return response.text().then(text => {
                        throw new Error(`HTTP ${response.status}: ${text.substring(0, 200)}`);
                    });
                }
                return response.json();
            })
            .then(data => {
                sendBtn.innerHTML = originalText;
                sendBtn.disabled = false;
                
                if (data.success) {
                    showToast(data.message, 'success');
                    if (typeof BozzeEmail !== 'undefined') BozzeEmail.dopoInvio();
                    if (typeof ContattiRubrica !== 'undefined') {
                        ContattiRubrica.proponiDestinatari([to, formData.get('cc'), formData.get('bcc')].join(','));
                    }
                    
                    // ✅ PULIZIA COMPLETA SOLO DOPO INVIO RIUSCITO
                    const composeModal = document.getElementById('composeModal');
                    if (composeModal) {
                        composeModal.style.display = 'none';
                    }
                    
                    // ✅ PULIZIA FORM + EDITOR HTML
                    if (form) {
                        form.reset(); // Resetta tutti i campi
                        
                        // ✅ PULIZIA EDITOR HTML CONTENTEDITABLE
                        const emailEditor = document.getElementById('emailEditor');
                        if (emailEditor) {
                            emailEditor.innerHTML = '<div><br></div>'; // Reset editor
                        }
                    }
                    
                    // ✅ PULIZIA ALLEGATI
                    clearAttachments();
                    
                    // ✅ PULIZIA SERVER-SIDE allegati temporanei
                    fetch('clear_temp_attachment.php', {
                        method: 'POST',
                        credentials: 'include'
                    }).catch(() => {});
                } else {
                    const errorMsg = data.error || 'Errore sconosciuto durante l\'invio';
                    alert(`❌ Errore invio:\n${errorMsg}`);
                }
            })
            .catch(error => {
                sendBtn.innerHTML = originalText;
                sendBtn.disabled = false;
                alert(`Errore di rete durante l'invio:\n${error.message}`);
            });
        }

        /**
         * Apri modal composizione NUOVO MESSAGGIO con pulizia completa
         * Pulisce editor, allegati, stato globale prima dell'apertura
         */
        function composeEmail() {
            const accountScelto = document.getElementById('userSelect')?.value?.trim();
            if (!accountScelto && !currentUser) {
                showToast('Seleziona prima l\'account da cui scrivere', 'info');
                chiudiCartelle();
                return;
            }
            chiudiCartelle();

            // ✅ PULIZIA COMPLETA PRIMA DELL'APERTURA
            
            // 1. Resetta array allegati globali
            if (typeof selectedFiles !== 'undefined') {
                selectedFiles = [];
            }
            
            // 2. Pulisci preview allegati UI
            const attachmentPreview = document.getElementById('attachmentPreview');
            if (attachmentPreview) {
                attachmentPreview.innerHTML = '';
            }
            
            // 3. Resetta campo file input (nativo browser)
            const fileInput = document.getElementById('composeAttachments');
            if (fileInput) {
                fileInput.value = '';
            }
            
            // 4. Pulisci editor HTML contenteditable
            const emailEditor = document.getElementById('emailEditor');
            if (emailEditor) {
                emailEditor.innerHTML = '<div><br></div>'; // Struttura minima per editor funzionante
            }
            
            // 5. Pulisci textarea fallback
            const bodyTextarea = document.getElementById('composeBody');
            if (bodyTextarea) {
                bodyTextarea.value = '';
            }
            
            // 6. Nascondi warning inoltro (se visibile)
            const forwardWarning = document.getElementById('forwardWarning');
            if (forwardWarning) {
                forwardWarning.style.display = 'none';
            }
            
            // 7. Resetta campo hidden inoltro
            const forwardOriginalField = document.getElementById('forwardOriginalEmail');
            if (forwardOriginalField) {
                forwardOriginalField.value = '';
            }
            
            // ✅ APRI MODAL DOPO PULIZIA COMPLETA
            openComposeModal('new');
        }

        // ==================== FUNZIONI PER RISPOSTA ====================

        // Aggiorna lo stato dei bottoni Rispondi/Rispondi a tutti in base alla cartella corrente
        function updateReplyButtonState() {
            ['replyBtn', 'replyAllBtn'].forEach(id => {
                const btn = document.getElementById(id);
                if (!btn) return;

                if (currentFolder === 'inbox') {
                    btn.disabled = false;
                    btn.removeAttribute('title');
                    btn.classList.remove('btn-disabled');
                } else {
                    btn.disabled = true;
                    btn.title = 'Disponibile solo per le email in Posta in arrivo';
                    btn.classList.add('btn-disabled');
                }
            });
        }

        // Estrai email da stringa "Nome <email@example.com>"
        function extractEmail(str) {
            if (!str) return '';
            const match = str.match(/<([^>]+)>/);
            return match ? match[1].trim() : str.trim();
        }

        // Estrae gli indirizzi email puri da un header (es. "Nome <a@b>, c@d")
        // Restituisce una lista separata da virgola, senza duplicati, escluso l'indirizzo in `exclude`
        function parseAddressList(header, exclude = '') {
            if (!header) return '';
            const excludeLower = exclude.trim().toLowerCase();
            const emails = header.match(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g) || [];
            return [...new Set(emails)]
                .filter(e => e.toLowerCase() !== excludeLower)
                .join(', ');
        }

        // ==================== FUNZIONE DI PROTEZIONE USER SELECT ====================
        /**
         * Garantisce che il div user-select-container sia SEMPRE visibile
         * Viene chiamata dopo OGNI operazione che modifica la sidebar
         */
        function protectUserSelectContainer() {
            const container = document.getElementById('userSelectContainer');
            if (!container) {
                // Ricrea il container come ultima risorsa
                recreateUserSelectContainer();
                return false;
            }
            
            // Forza visibilità con stili inline
            container.style.display = 'block';
            container.style.opacity = '1';
            container.style.visibility = 'visible';
            container.style.position = 'relative';
            container.style.zIndex = '1000';
            
            // Rimuovi classi di nascondimento
            container.classList.remove('hidden', 'invisible', 'd-none');
            
            return true;
        }
        /**
         * Ricrea il container utente se è stato rimosso accidentalmente
         */
        function recreateUserSelectContainer() {
            
            const sidebarHeader = document.querySelector('.sidebar-header');
            if (!sidebarHeader) {
                return;
            }
            
            // Verifica se esiste già
            let container = document.getElementById('userSelectContainer');
            if (container) {
                protectUserSelectContainer();
                return;
            }
            
            // Crea nuovo container
            container = document.createElement('div');
            container.id = 'userSelectContainer';
            container.className = 'user-select-container';
            container.innerHTML = `
                <label for="userSelect">
                    <i class="fas fa-at"></i> Account:
                </label>
                <select id="userSelect" onchange="loadEmails()">
                    <option value="">-- Seleziona un account --</option>
                </select>
            `;
            
            // Inserisci DOPO l'header ma PRIMA della search bar
            const searchContainer = document.querySelector('.search-container');
            if (searchContainer) {
                sidebarHeader.insertBefore(container, searchContainer);
            } else {
                sidebarHeader.appendChild(container);
            }
            
            // Ricarica gli utenti nel select
            setTimeout(loadUsers, 100);
            
            // Proteggi immediatamente
            protectUserSelectContainer();
        }

        function replyEmail(replyAll = false) {
            // ✅ RESETTA CAMPO forward_original_email PRIMA di aprire il modal
            const forwardField = document.getElementById('forwardOriginalEmail');
            if (forwardField) forwardField.value = '';
            // Verifica che ci sia un'email selezionata
            clearAttachments();
            
            if (!currentEmailFile || !currentUser) {
                showToast('Nessuna email selezionata', 'error');
                return;
            }
            
            // Recupera i dettagli dell'email per precompilare il composer
            // ✅ CORREZIONE FONDAMENTALE: Leggi SEMPRE l'utente dal dropdown (non dalla variabile globale)
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;

            fetch('get_email_detail.php', {
                method: 'POST',
                credentials: 'include',  // ✅ INCLUDE I COOKIE DI SESSIONE
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder || currentFolder)}`            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                // Apri il composer in modalità risposta (o risposta a tutti)
                openReplyComposer(data, replyAll);
                
            })
            .catch(error => {
                showToast('Errore: ' + error.message, 'error');
            });
        }

        // Rispondi a tutti: A = mittente (Da:), Cc = indirizzi presenti nel Cc originale
        function replyAllEmail() {
            replyEmail(true);
        }

        /**
         * Apri il composer in modalità risposta con citazione automatica e separatore standard
         * @param {object} emailData - Dati email da cui rispondere
         * @param {boolean} replyAll - Se true, precompila anche Cc con gli indirizzi Cc originali
         */
        function openReplyComposer(emailData, replyAll = false) {
            // Determina il destinatario in base alla cartella
            let replyTo = '';
            let originalSubject = emailData.subject || '';
            let originalBody = emailData.text_body || '';
            let originalHtmlBody = emailData.html_body || ''; // ✅ Supporto HTML nativo
            
            // Per email in Sent, rispondi al mittente originale
            // Per email in Inbox/Draft/Junk, rispondi al mittente
            // Per PEC: il campo Reply-To contiene l'indirizzo reale del mittente
            //          (From contiene "Per conto di: ..." del gestore PEC)
            if (emailFolder === 'sent' || currentFolder === 'sent') {
                // Email inviata: rispondi al destinatario originale
                replyTo = emailData.to || '';
            } else {
                // Email ricevuta: usa Reply-To se presente (PEC), altrimenti From
                replyTo = (emailData.reply_to && emailData.reply_to.trim())
                    ? emailData.reply_to
                    : (emailData.from || '');
            }
            
            // Formatta oggetto con "Re:"
            if (!originalSubject.toUpperCase().startsWith('RE:')) {
                originalSubject = 'Re: ' + originalSubject;
            }
            
            // ✅ DETERMINA FORMATO (HTML vs TESTO) in base ai dati disponibili
            const isHtmlReply = (originalHtmlBody && originalHtmlBody.trim().length > 0) || 
                                (document.getElementById('composeIsHtml')?.value === '1');
            
            // ✅ FORMATTAZIONE CITAZIONE CON SEPARATORE STANDARD
            let replyBody = '';
            const separatorLine = "<br>==============================================";
            
            if (isHtmlReply) {
                // ✅ CITAZIONE HTML CON SEPARATORE VISIVO (linea di "=" + border)
                const formattedDate = emailData.date_formatted || emailData.date || 'Data sconosciuta';
                const fromName = emailData.from_name || emailData.from || 'Sconosciuto';
                const toName = emailData.to_name || emailData.to || 'Sconosciuto';
                const originalSubjectClean = originalSubject.replace(/^Re:\s*/, '');
                
                // Pulisci HTML per sicurezza e compatibilità
                const cleanHtmlBody = pulisciHtmlEmail(originalHtmlBody);
                
                // ✅ SEPARATORE HTML: linea di "=" + spazio + citazione con stile standard
                replyBody = `
        <div style="margin-bottom: 12px; color: #888; font-family: monospace; font-size: 13px;">${separatorLine}</div>
        <div style="font-family: Arial, sans-serif; font-size: 13px; color: #5f6368; border-left: 2px solid #d1d1d1; padding-left: 12px; margin: 12px 0;">
            <div style="margin-bottom: 8px;">
                <span style="font-weight: bold;">${escapeHtml(fromName)}</span> 
                <span style="color: #808080;">&lt;${escapeHtml(emailData.from || 'Sconosciuto')}&gt;</span>
            </div>
            <div style="margin-bottom: 8px; color: #808080;">
                ${escapeHtml(formattedDate)}
            </div>
            <div style="margin-bottom: 8px;">
                <span style="font-weight: bold;">A:</span> ${escapeHtml(toName)}
            </div>
            <div style="margin-bottom: 8px;">
                <span style="font-weight: bold;">Oggetto:</span> ${escapeHtml(originalSubjectClean)}
            </div>
            <div style="margin-top: 12px; padding-top: 8px; border-top: 1px solid #e0e0e0;">
                ${cleanHtmlBody || '<p style="margin: 0; color: #5f6368;">(nessun contenuto)</p>'}
            </div>
        </div>
                `.trim();
            } else {
                // ✅ CITAZIONE TESTO SEMPLICE CON SEPARATORE STANDARD (linea di "=")
                const formattedDate = emailData.date || 'Data sconosciuta';
                const fromEmail = emailData.from || 'Sconosciuto';
                const toEmail = emailData.to || 'Sconosciuto';
                const originalSubjectClean = originalSubject.replace(/^Re:\s*/, '');
                
                // Formatta corpo con "> " per ogni riga
                const quotedLines = originalBody
                    .split('\n')
                    .map(line => `> ${line}`)
                    .join('\n');
                
                // ✅ SEPARATORE TESTO: linea di "=" tra due ritorni a capo
                replyBody = `
        ${separatorLine}
        Il ${formattedDate}, ${fromEmail} ha scritto:
        > A: ${toEmail}
        > Oggetto: ${originalSubjectClean}
        >
        ${quotedLines}
                `.trim();
            }
            
            // ✅ APRI IL COMPOSER CON DATI PRECOMPILATI
            const composeModal = document.getElementById('composeModal');
            if (!composeModal) {
                showToast('Errore: interfaccia di composizione non disponibile', 'error');
                return;
            }
            
            composeModal.style.display = 'flex';
            
            // Precompila il form
            const toInput = document.getElementById('composeTo');
            const subjectInput = document.getElementById('composeSubject');
            const bodyTextarea = document.getElementById('composeBody');
            const isHtmlInput = document.getElementById('composeIsHtml');
            
            // ✅ Blocca l'account mittente nel form al momento dell'apertura della risposta
            const accountIdInput = document.getElementById('composeAccountId');
            if (accountIdInput) {
                const userSelectEl = document.getElementById('userSelect');
                const senderUser = (userSelectEl && userSelectEl.value.trim()) ? userSelectEl.value.trim() : currentUser;
                accountIdInput.value = senderUser;
            }
            impostaRigaDa();

            if (toInput) toInput.value = extractEmailAddress(replyTo) || '';
            if (subjectInput) subjectInput.value = originalSubject;

            // ✅ RISPONDI A TUTTI: precompila Cc con gli indirizzi del Cc originale
            const ccInput = document.getElementById('composeCc');
            if (ccInput) {
                if (replyAll && emailData.cc) {
                    ccInput.value = parseAddressList(emailData.cc, toInput ? toInput.value : '');
                } else {
                    ccInput.value = '';
                }
            }
            
            // ✅ IMPOSTA FORMATO CORRETTO (HTML o testo)
            if (isHtmlInput) {
                isHtmlInput.value = isHtmlReply ? '1' : '0';
            }
            
            // ✅ GESTIONE EDITOR HTML vs TEXTAREA CON POSIZIONAMENTO CURSORE SOPRA IL SEPARATORE
            const emailEditor = document.getElementById('emailEditor');
            
            if (isHtmlReply && emailEditor) {
                // Modalità HTML: usa l'editor contenteditable
                emailEditor.innerHTML = replyBody;
                bodyTextarea.value = ''; // Pulisci textarea nascosta
                
                // ✅ POSIZIONA IL CURSORE ALL'INIZIO (SOPRA il separatore)
                setTimeout(() => {
                    const range = document.createRange();
                    const sel = window.getSelection();
                    range.selectNodeContents(emailEditor);
                    range.collapse(true); // All'inizio del contenuto (prima del separatore)
                    sel.removeAllRanges();
                    sel.addRange(range);
                    emailEditor.focus();
                }, 100);
                
                // Mostra editor HTML, nascondi textarea
                emailEditor.style.display = 'block';
                if (bodyTextarea) bodyTextarea.style.display = 'none';
                
            } else if (bodyTextarea) {
                // Modalità testo: usa la textarea
                bodyTextarea.value = replyBody;
                
                // ✅ POSIZIONA IL CURSORE ALL'INIZIO (SOPRA il separatore)
                setTimeout(() => {
                    bodyTextarea.setSelectionRange(0, 0);
                    bodyTextarea.focus();
                }, 100);
                
                // Mostra textarea, nascondi editor HTML
                bodyTextarea.style.display = 'block';
                if (emailEditor) emailEditor.style.display = 'none';
            }
            
            // ✅ AGGIORNA INTERFACCIA VISIVA (pulsanti formato)
            updateFormatButtons(isHtmlReply ? 'html' : 'text');
            
            // Imposta stato composizione
            isReplying = true;
            isForwarding = false;
            isComposing = true;
            
        }

        /**
         * Aggiorna lo stato visivo dei pulsanti formato (HTML/Testo)
         * @param {string} activeFormat - 'html' o 'text'
         */
        function updateFormatButtons(activeFormat) {
            const formatBtns = document.querySelectorAll('.format-btn');
            formatBtns.forEach(btn => btn.classList.remove('active'));
            
            if (activeFormat === 'html') {
                // Attiva pulsante HTML (cerca per testo o posizione)
                const htmlBtn = Array.from(formatBtns).find(btn => 
                    btn.textContent?.toLowerCase().includes('html') || btn.dataset.format === 'html'
                );
                if (htmlBtn) htmlBtn.classList.add('active');
            } else {
                // Attiva pulsante Testo
                const textBtn = Array.from(formatBtns).find(btn => 
                    btn.textContent?.toLowerCase().includes('testo') || btn.dataset.format === 'text'
                );
                if (textBtn) textBtn.classList.add('active');
            }
        }

// ==================== FUNZIONI IMPOSTAZIONI ====================

// Toggle menu impostazioni
function toggleSettingsDropdown(event) {
    event.stopPropagation();
    const menu = document.getElementById('settingsMenu');
    menu.classList.toggle('show');
    
    // Chiudi menu quando si clicca fuori
    if (menu.classList.contains('show')) {
        document.addEventListener('click', closeSettingsMenuOnClickOutside);
    } else {
        document.removeEventListener('click', closeSettingsMenuOnClickOutside);
    }
}

    // Chiudi menu cliccando fuori
    function closeSettingsMenuOnClickOutside(event) {
        const menu = document.getElementById('settingsMenu');
        const btn = event.target.closest('.settings-btn');
        
        if (!menu.contains(event.target) && !btn) {
            menu.classList.remove('show');
            document.removeEventListener('click', closeSettingsMenuOnClickOutside);
        }
    }

    // Contatti: showContatti() è in contatti.js (rubrica di PiGreco Archivio)

    // Chiudi menu impostazioni durante lo scroll
    window.addEventListener('scroll', function() {
        const menu = document.getElementById('settingsMenu');
        if (menu.classList.contains('show')) {
            menu.classList.remove('show');
            document.removeEventListener('click', closeSettingsMenuOnClickOutside);
        }
    }, { passive: true });
        
    // ==================== FUNZIONI ICONA EMAIL ====================

    // Apre il modal di composizione con il destinatario pre-compilato
    function composeToEmail(email, fieldName) {
        if (!email) {
            showToast('Indirizzo email non valido', 'error');
            return;
        }
        
        // Apri modal composizione
        openComposeModal('new');
        
        // Imposta il campo "A:" dopo un breve delay (per dare tempo al modal di aprirsi)
        setTimeout(() => {
            const toField = document.getElementById('composeTo');
            if (toField) {
                toField.value = email;
                toField.focus();
                showToast(`📧 ${fieldName} pre-compilato: ${email}`, 'success');
            }
        }, 300);
    }

    // Chiudi menu quando si apre un'altra finestra modale
    document.addEventListener('click', function(e) {
        if (e.target.closest('.compose-modal') || 
            e.target.closest('.search-dialog') || 
            e.target.closest('.preview-modal')) {
            const menu = document.getElementById('settingsMenu');
            if (menu.classList.contains('show')) {
                menu.classList.remove('show');
                document.removeEventListener('click', closeSettingsMenuOnClickOutside);
            }
        }
    });

    // Event listener per icone email nella lista
    document.addEventListener('click', function(e) {
        const icon = e.target.closest('.email-list-icon');
        if (icon) {
            e.stopPropagation(); // Previene il click sull'email item
            const email = icon.getAttribute('data-email');
            composeToEmail(email, 'Destinatario');
        }
    });
    // Carica utenti all'avvio
    document.addEventListener('DOMContentLoaded', function() {
        // ✅ Imposta Inbox come cartella attiva all'avvio
        document.querySelectorAll('.folder-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        const inboxBtn = document.querySelector('.folder-btn[data-folder="inbox"]');
        if (inboxBtn) {
            inboxBtn.classList.add('active');
            currentFolder = 'inbox'; // Inizializza currentFolder
        }
        // ✅ AGGIUNGI QUESTA CHIAMATA ALL'INIZIO
        protectUserSelectContainer();
        loadUsers();
        // ... resto del codice ...    // Proteggi periodicamente (ogni 2 secondi) come safety net
        setInterval(protectUserSelectContainer, 2000);
    })
    // ==================== LOGOUT ====================
    document.addEventListener('DOMContentLoaded', function() {
        const logoutBtn = document.getElementById('logoutBtn');
        
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function() {
                // Chiedi conferma prima del logout
                if (confirm('Vuoi davvero uscire dall\'applicazione?')) {
                    // Reindirizza alla pagina di logout
                    window.location.href = 'logout.php';
                }
            });
        }
    });

    // ==================== GESTIONE IMPOSTAZIONI ====================

    /**
     * Apre la finestra Impostazioni sul pannello indicato (default: account email,
     * la nuova gestione IMAP/SMTP).
     */
    function showSettings(initialPanel = 'mailaccounts') {
        // Le Impostazioni sono riservate all'amministratore
        const settingsModalEl = document.getElementById('settingsModal');
        if (!is_admin || !settingsModalEl) {
            showToast('Accesso riservato all\'amministratore', 'error');
            return;
        }

        // Chiudi menu impostazioni
        document.getElementById('settingsMenu')?.classList.remove('show');
        document.removeEventListener('click', closeSettingsMenuOnClickOutside);

        // Apri finestra impostazioni sul pannello richiesto
        settingsModalEl.style.display = 'flex';
        switchSettingsPanel(initialPanel);

    }

    function closeSettingsModal() {
        document.getElementById('settingsModal').style.display = 'none';
        cancelMailAccountForm();
        cancelUserForm();
    }

    /**
     * Attiva il pannello indicato ('mailaccounts', 'account' o 'log') nella
     * sidebar della finestra Impostazioni, e carica i relativi dati.
     */
    function switchSettingsPanel(panelName) {
        document.querySelectorAll('.settings-sidebar-item').forEach(item => {
            item.classList.toggle('active', item.getAttribute('data-settings-panel') === panelName);
        });
        document.querySelectorAll('.settings-panel').forEach(panel => {
            panel.classList.remove('active');
        });

        const panelIds = {
            mailaccounts: 'settingsPanelMailAccounts',
            account: 'settingsPanelAccount',
            log: 'settingsPanelLog'
        };
        const panelEl = document.getElementById(panelIds[panelName] || panelIds.mailaccounts);
        if (panelEl) panelEl.classList.add('active');

        if (panelName === 'account') {
            loadWebUsers();
        } else if (panelName === 'log') {
            loadAccessLog();
        } else {
            loadMailAccounts();
        }
    }

    // ==================== LOG ACCESSI (pannello "Log Accessi" delle Impostazioni) ====================

    function loadAccessLog() {
        const tbody = document.getElementById('accessLogTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="loading-accounts">Caricamento log...</td></tr>';

        const usernameFilter = document.getElementById('logSearchUsername')?.value.trim() || '';
        const params = new URLSearchParams({ action: 'list', limit: '100' });
        if (usernameFilter) params.set('username', usernameFilter);

        fetch(`api/get_access_log.php?${params.toString()}`)
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.logs) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px; color: #ef4444;">
                                Errore caricamento log: ${escapeHtml(data.error || 'Errore sconosciuto')}
                            </td>
                        </tr>
                    `;
                    return;
                }

                if (data.logs.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #6c757d;">
                                Nessuna voce di log trovata
                            </td>
                        </tr>
                    `;
                    return;
                }

                tbody.innerHTML = data.logs.map(log => `
                    <tr>
                        <td>${escapeHtml(log.attempt_time || '')}</td>
                        <td><strong>${escapeHtml(log.username)}</strong></td>
                        <td>${escapeHtml(log.action || '')}</td>
                        <td style="text-align:center;">
                            ${log.success
                                ? '<span style="color:#15803d;"><i class="fas fa-check-circle"></i> OK</span>'
                                : '<span style="color:#b91c1c;"><i class="fas fa-times-circle"></i> Fallito</span>'}
                        </td>
                        <td>${escapeHtml(log.ip_address || '')}</td>
                        <td style="max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="${escapeHtmlAttr(log.user_agent || '')}">${escapeHtml(log.user_agent || '')}</td>
                    </tr>
                `).join('');
            })
            .catch(error => {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px; color: #ef4444;">
                            Errore di rete: ${escapeHtml(error.message)}
                        </td>
                    </tr>
                `;
            });
    }

    // ==================== ACCOUNT EMAIL (pannello "Account Email" delle Impostazioni) ====================

    let mailAccountsCache = [];
    let mailAccountUsers = [];
    let mailAccountDefaults = { imap_host: '', imap_port: 143, imap_security: 'starttls' };

    function loadMailAccounts() {
        const tbody = document.getElementById('mailAccountsTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="loading-accounts">Caricamento account...</td></tr>';

        fetch('api/manage_mail_accounts.php?action=list')
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Errore sconosciuto');
                }
                mailAccountsCache = data.accounts || [];
                mailAccountUsers = data.users || [];
                mailAccountDefaults = data.defaults || mailAccountDefaults;

                if (mailAccountsCache.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 30px; color: #6c757d;">
                                <i class="fas fa-inbox" style="font-size: 2em; margin-bottom: 10px;"></i>
                                <div>Nessun account email configurato</div>
                                <div style="font-size: 0.9em; margin-top: 8px; color: #9ca3af;">
                                    Clicca su "Nuovo Account Email" per aggiungere il primo
                                </div>
                            </td>
                        </tr>`;
                    return;
                }

                const userName = id => {
                    const u = mailAccountUsers.find(x => x.id === id);
                    return u ? `${u.nome} ${u.cognome}` : `#${id}`;
                };

                tbody.innerHTML = mailAccountsCache.map(a => {
                    const visibility = a.visibilita === 'tutti'
                        ? 'Tutti'
                        : (a.user_ids.length ? a.user_ids.map(userName).join(', ') : '<em>nessuno</em>');
                    const check = !a.last_check
                        ? '<span style="color:#9ca3af;">mai</span>'
                        : (a.last_error
                            ? `<span style="color:#ef4444;" title="${escapeHtmlAttr(a.last_error)}"><i class="fas fa-times-circle"></i> errore</span>`
                            : `<span style="color:#10b981;" title="${escapeHtmlAttr(a.last_check)}"><i class="fas fa-check-circle"></i> ok</span>`);
                    return `
                        <tr>
                            <td><strong>${escapeHtml(a.nome_visualizzato)}</strong>${a.societa ? `<br><small>${escapeHtml(a.societa)}</small>` : ''}</td>
                            <td>${escapeHtml(a.email)}</td>
                            <td>${escapeHtml(a.imap_username)}@${escapeHtml(a.imap_host)}:${a.imap_port}<br><small>${escapeHtml(a.imap_security.toUpperCase())}</small></td>
                            <td>${escapeHtml(a.smtp_host)}:${a.smtp_port}<br><small>${escapeHtml(a.smtp_encryption.toUpperCase())}</small></td>
                            <td>${visibility}</td>
                            <td style="text-align:center;">${a.attivo ? '<i class="fas fa-check" style="color:#10b981;"></i>' : '<i class="fas fa-ban" style="color:#9ca3af;"></i>'}</td>
                            <td>${check}</td>
                            <td class="account-actions">
                                <button class="btn-account-edit" title="Modifica" onclick="editMailAccount(${a.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-account-delete" title="Elimina" onclick="deleteMailAccount(${a.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>`;
                }).join('');
            })
            .catch(error => {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 20px; color: #ef4444;">
                            Errore caricamento account: ${escapeHtml(error.message)}
                        </td>
                    </tr>`;
            });
    }

    function renderMailAccountUsers(selectedIds) {
        const box = document.getElementById('maUsersList');
        if (mailAccountUsers.length === 0) {
            box.innerHTML = '<em>Nessun utente attivo nella webmail</em>';
            return;
        }
        box.innerHTML = mailAccountUsers.map(u => `
            <label>
                <input type="checkbox" name="user_ids[]" value="${u.id}" ${selectedIds.includes(u.id) ? 'checked' : ''}>
                ${escapeHtml(u.nome)} ${escapeHtml(u.cognome)} <small>(${escapeHtml(u.username)})</small>
            </label>`).join('');
    }

    function onMailVisibilityChange() {
        const selected = document.querySelector('#mailAccountForm input[name="visibilita"]:checked').value;
        document.getElementById('maUsersList').style.display = selected === 'selezionati' ? 'block' : 'none';
    }

    function onMailSecurityChange(kind) {
        if (kind === 'imap') {
            const sec = document.getElementById('maImapSecurity').value;
            document.getElementById('maImapPort').value = sec === 'ssl' ? 993 : 143;
        } else {
            const sec = document.getElementById('maSmtpEncryption').value;
            document.getElementById('maSmtpPort').value = sec === 'ssl' ? 465 : (sec === 'tls' ? 587 : 25);
        }
    }

    function fillMailAccountForm(a) {
        const isNew = !a.id;
        document.getElementById('mailAccountFormTitle').textContent = isNew ? 'Nuovo Account Email' : 'Modifica Account Email';
        document.getElementById('maId').value = a.id || '';
        document.getElementById('maNome').value = a.nome_visualizzato || '';
        document.getElementById('maEmail').value = a.email || '';
        document.getElementById('maSocieta').value = a.societa || '';
        document.getElementById('maImapHost').value = a.imap_host || '';
        document.getElementById('maImapPort').value = a.imap_port || 143;
        document.getElementById('maImapSecurity').value = a.imap_security || 'starttls';
        document.getElementById('maImapUser').value = a.imap_username || '';
        document.getElementById('maImapPassword').value = '';
        document.getElementById('maSmtpHost').value = a.smtp_host || '';
        document.getElementById('maSmtpPort').value = a.smtp_port || 587;
        document.getElementById('maSmtpEncryption').value = a.smtp_encryption || 'tls';
        document.getElementById('maSmtpUser').value = a.smtp_username || '';
        document.getElementById('maSmtpPassword').value = '';
        document.getElementById('maAttivo').checked = a.attivo !== false;
        document.querySelector(`#mailAccountForm input[name="visibilita"][value="${a.visibilita === 'selezionati' ? 'selezionati' : 'tutti'}"]`).checked = true;
        renderMailAccountUsers(a.user_ids || []);
        onMailVisibilityChange();

        document.getElementById('maPasswordHint').style.display = isNew ? 'none' : 'block';
        document.querySelectorAll('.mail-pw-required').forEach(el => el.style.display = isNew ? '' : 'none');
        const result = document.getElementById('maTestResult');
        result.style.display = 'none';
        result.innerHTML = '';

        document.getElementById('mailAccountFormContainer').style.display = 'block';
        document.getElementById('maNome').focus();
    }

    function openAddMailAccountForm() {
        fillMailAccountForm({
            imap_host: mailAccountDefaults.imap_host,
            imap_port: mailAccountDefaults.imap_port,
            imap_security: mailAccountDefaults.imap_security,
            visibilita: 'tutti',
            attivo: true
        });
    }

    function editMailAccount(id) {
        const account = mailAccountsCache.find(a => a.id === id);
        if (account) {
            fillMailAccountForm(account);
        }
    }

    function cancelMailAccountForm() {
        document.getElementById('mailAccountFormContainer').style.display = 'none';
        document.getElementById('mailAccountForm').reset();
    }

    function getMailAccountFormData(action) {
        const formData = new FormData(document.getElementById('mailAccountForm'));
        formData.set('action', action);
        formData.set('attivo', document.getElementById('maAttivo').checked ? '1' : '0');
        return formData;
    }

    function testMailAccount() {
        const btn = document.getElementById('maTestBtn');
        const result = document.getElementById('maTestResult');
        btn.disabled = true;
        result.style.display = 'block';
        result.className = 'mail-account-test';
        result.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Test in corso...';

        fetch('api/manage_mail_accounts.php', { method: 'POST', body: getMailAccountFormData('test') })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Errore sconosciuto');
                }
                const line = (label, r) => `
                    <div class="${r.ok ? 'mail-test-ok' : 'mail-test-ko'}">
                        <i class="fas ${r.ok ? 'fa-check-circle' : 'fa-times-circle'}"></i>
                        <strong>${label}:</strong> ${escapeHtml(r.message)}
                    </div>`;
                let html = line('IMAP', data.imap) + line('SMTP', data.smtp);
                if (data.imap.ok && data.imap.folders) {
                    html += `<div class="mail-test-folders"><strong>Cartelle:</strong> ${data.imap.folders.map(escapeHtml).join(', ')}</div>`;
                }
                result.innerHTML = html;
                if (document.getElementById('maId').value) {
                    loadMailAccounts();
                }
            })
            .catch(error => {
                result.innerHTML = `<div class="mail-test-ko"><i class="fas fa-times-circle"></i> ${escapeHtml(error.message)}</div>`;
            })
            .finally(() => { btn.disabled = false; });
    }

    function saveMailAccount() {
        fetch('api/manage_mail_accounts.php', { method: 'POST', body: getMailAccountFormData('save') })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    cancelMailAccountForm();
                    loadMailAccounts();
                } else {
                    alert('Errore salvataggio: ' + data.error);
                }
            })
            .catch(error => {
                alert('Errore durante il salvataggio: ' + error.message);
            });
    }

    function deleteMailAccount(id) {
        const account = mailAccountsCache.find(a => a.id === id);
        const name = account ? account.nome_visualizzato : '#' + id;
        if (!confirm(`Conferma eliminazione dell'account email "${name}"?\nLe email sul server non vengono toccate.`)) {
            return;
        }
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        fetch('api/manage_mail_accounts.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    cancelMailAccountForm();
                    loadMailAccounts();
                } else {
                    alert('Errore eliminazione: ' + data.error);
                }
            })
            .catch(error => {
                alert('Errore durante l\'eliminazione: ' + error.message);
            });
    }

    // ==================== GESTIONE ACCOUNT (pannello "Account" delle Impostazioni) ====================

    let webUsersCache = [];

    /**
     * Carica l'elenco degli account di accesso alla webapp (tabella "users").
     */
    function loadWebUsers() {
        const tbody = document.getElementById('webUsersTableBody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="loading-accounts">Caricamento account...</td></tr>';

        fetch('api/manage_users.php?action=list')
            .then(response => response.json())
            .then(data => {
                if (!data.success || !data.users) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 20px; color: #ef4444;">
                                Errore caricamento account: ${data.error || 'Errore sconosciuto'}
                            </td>
                        </tr>
                    `;
                    return;
                }

                webUsersCache = data.users;

                if (data.users.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 30px; color: #6c757d;">
                                Nessun account configurato
                            </td>
                        </tr>
                    `;
                    return;
                }

                tbody.innerHTML = data.users.map(u => `
                    <tr>
                        <td>${escapeHtml(u.nome)} ${escapeHtml(u.cognome)}</td>
                        <td><strong>${escapeHtml(u.username)}</strong></td>
                        <td>${escapeHtml(u.email)}</td>
                        <td>${u.tipo_user === 'admin' ? 'Amministratore' : 'Operatore'}</td>
                        <td style="text-align:center;">${u.attivo ? '<i class="fas fa-check" style="color:#22c55e;"></i>' : '<i class="fas fa-times" style="color:#ef4444;"></i>'}</td>
                        <td class="account-actions">
                            <button class="btn-account-edit" title="Modifica" onclick="editUserAccount(${u.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-account-edit" title="Cambia password" onclick="changeUserAccountPassword(${u.id}, '${escapeHtmlAttr(u.username)}')">
                                <i class="fas fa-key"></i>
                            </button>
                            <button class="btn-account-delete" title="Elimina" onclick="deleteUserAccount(${u.id}, '${escapeHtmlAttr(u.username)}')">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `).join('');
            })
            .catch(error => {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 20px; color: #ef4444;">
                            Errore di rete: ${error.message}
                        </td>
                    </tr>
                `;
            });
    }

    function openAddUserForm() {
        document.getElementById('userFormTitle').textContent = 'Nuovo Account';
        document.getElementById('userAccountForm').reset();
        document.getElementById('formUserId').value = '';
        document.getElementById('formUserUsername').disabled = false;
        document.getElementById('formUserAttivo').checked = true;
        document.getElementById('formUserPasswordRow').style.display = 'block';
        document.getElementById('formUserPassword').required = true;
        document.getElementById('userFormContainer').style.display = 'block';
        document.getElementById('formUserNome').focus();
    }

    function cancelUserForm() {
        const container = document.getElementById('userFormContainer');
        if (container) container.style.display = 'none';
    }

    function editUserAccount(id) {
        const u = webUsersCache.find(x => String(x.id) === String(id));
        if (!u) {
            alert('Account non trovato');
            return;
        }

        document.getElementById('userFormTitle').textContent = 'Modifica Account';
        document.getElementById('formUserId').value = u.id;
        document.getElementById('formUserNome').value = u.nome;
        document.getElementById('formUserCognome').value = u.cognome;
        document.getElementById('formUserUsername').value = u.username;
        document.getElementById('formUserEmail').value = u.email;
        document.getElementById('formUserTipo').value = u.tipo_user;
        document.getElementById('formUserAttivo').checked = !!u.attivo;

        // Il cambio password ha un percorso dedicato (pulsante "Cambia password")
        document.getElementById('formUserPasswordRow').style.display = 'none';
        document.getElementById('formUserPassword').required = false;
        document.getElementById('formUserPassword').value = '';

        document.getElementById('userFormContainer').style.display = 'block';
        document.getElementById('formUserNome').focus();
    }

    function saveUserAccount() {
        const id = document.getElementById('formUserId').value;
        const formData = new FormData();
        formData.append('action', id ? 'update' : 'create');
        if (id) formData.append('id', id);
        formData.append('nome', document.getElementById('formUserNome').value.trim());
        formData.append('cognome', document.getElementById('formUserCognome').value.trim());
        formData.append('username', document.getElementById('formUserUsername').value.trim());
        formData.append('email', document.getElementById('formUserEmail').value.trim());
        formData.append('tipo_user', document.getElementById('formUserTipo').value);
        formData.append('attivo', document.getElementById('formUserAttivo').checked ? '1' : '0');

        if (!id) {
            const password = document.getElementById('formUserPassword').value;
            if (!password || password.length < 8) {
                alert('Password obbligatoria (minimo 8 caratteri) per i nuovi account');
                return;
            }
            formData.append('password', password);
        }

        fetch('api/manage_users.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                cancelUserForm();
                loadWebUsers();
            } else {
                alert('Errore salvataggio account: ' + data.error);
            }
        })
        .catch(error => {
            alert('Errore durante il salvataggio: ' + error.message);
        });
    }

    function changeUserAccountPassword(id, username) {
        const password = prompt(`Nuova password per "${username}" (minimo 8 caratteri):`);
        if (password === null) return; // Annullato
        if (password.length < 8) {
            alert('La password deve essere di almeno 8 caratteri');
            return;
        }

        fetch('api/manage_users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=set_password&id=${encodeURIComponent(id)}&password=${encodeURIComponent(password)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
            } else {
                alert('Errore cambio password: ' + data.error);
            }
        })
        .catch(error => {
            alert('Errore di rete durante il cambio password: ' + error.message);
        });
    }

    function deleteUserAccount(id, username) {
        if (!confirm(`Conferma eliminazione dell'account "${username}"?\nQuesta azione non può essere annullata.`)) {
            return;
        }

        fetch('api/manage_users.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=delete&id=${encodeURIComponent(id)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
                loadWebUsers();
            } else {
                alert('Errore eliminazione: ' + data.error);
            }
        })
        .catch(error => {
            alert('Errore durante l\'eliminazione: ' + error.message);
        });
    }

    // ==================== FUNZIONI DI PAGINAZIONE ====================

    /**
     * Cambia il numero di email per pagina
     */
    function changePerPage() {
        const perPageSelect = document.getElementById('perPageSelect');
        if (perPageSelect) {
            emailsPerPage = parseInt(perPageSelect.value);
            
            // Ricarica dalla pagina 1 con il nuovo limite
            currentPage = 1;
            loadEmails(1);
        }
    }

    /**
     * Vai a una pagina specifica
     * @param {number} page - Numero della pagina (1-based)
     */
    function goToPage(page) {
        // Validazione pagina
        if (page < 1) {
            page = 1;
        }
        
        if (page > totalPages) {
            page = totalPages;
        }
        
        if (page === currentPage) {
            return;
        }
        
        // Aggiorna currentPage PRIMA di caricare
        currentPage = page;
        
        // Ricarica email per la pagina richiesta
        loadEmails(page);
        
        // Scrolla in cima alla lista email
        const emailList = document.getElementById('emailList');
        if (emailList) {
            emailList.scrollTop = 0;
        }
    }

    /**
     * Vai alla pagina precedente
     */
    function prevPage() {
        if (currentPage > 1) {
            goToPage(currentPage - 1);
        }
    }

    /**
     * Vai alla pagina successiva
     */
    function nextPage() {
        if (currentPage < totalPages) {
            goToPage(currentPage + 1);
        }
    }

    /**
     * Aggiorna le informazioni di paginazione nella UI
     * @param {number} total - Totale email
     * @param {number} perPage - Email per pagina
     * @param {number} current - Pagina corrente
     * @param {number} totalPg - Totale pagine
     */
    function updatePaginationInfo(total, perPage, current, totalPg) {
        // Aggiorna contatori pagina
        const currentPageDisplay = document.getElementById('currentPageDisplay');
        const totalPagesDisplay = document.getElementById('totalPagesDisplay');
        const paginationInfo = document.getElementById('paginationInfo');
        const prevBtn = document.getElementById('prevPageBtn');
        const nextBtn = document.getElementById('nextPageBtn');
        
        if (currentPageDisplay) currentPageDisplay.textContent = current;
        if (totalPagesDisplay) totalPagesDisplay.textContent = totalPg;
        
        // Calcola range email visualizzate
        const startEmail = ((current - 1) * perPage) + 1;
        const endEmail = Math.min(current * perPage, total);
        
        // Formatta testo paginazione
        let pageInfoText = '';
        
        if (total === 0) {
            pageInfoText = 'Nessuna email';
        } else if (totalPg === 1) {
            pageInfoText = `${total.toLocaleString('it-IT')} email`;
        } else {
            pageInfoText = `${startEmail.toLocaleString('it-IT')}–${endEmail.toLocaleString('it-IT')} di ${total.toLocaleString('it-IT')}`;
        }
        
        if (paginationInfo) {
            paginationInfo.textContent = pageInfoText;
        }
        
        // Abilita/disabilita bottoni in base alla pagina corrente
        if (prevBtn) {
            prevBtn.disabled = (current <= 1);
            prevBtn.title = prevBtn.disabled ? 'Prima pagina' : 'Pagina precedente';
        }
        
        if (nextBtn) {
            nextBtn.disabled = (current >= totalPg || total === 0);
            nextBtn.title = nextBtn.disabled ? 'Ultima pagina' : 'Pagina successiva';
        }
        
    }
    // ===================================================================

    // ==================== MARCA COME NON LETTO ====================
    /**
     * Sposta l'email corrente da cur a new (marca come non letta)
     */

    async function markAsUnread() {
        if (!currentUser || !currentEmailFile || !emailFolder) {
            showToast('Errore: Dati email non disponibili', 'error');
            return;
        }

        // Chiedi conferma all'utente
        if (!confirm('Contrassegnare questa email come "Non letta"?\nApparirà di nuovo con sfondo arancione.')) {
            return;
        }

        try {
            // Disabilita pulsanti durante l'operazione
            const unreadBtn = document.querySelector('.btn-unread');
            if (unreadBtn) {
                unreadBtn.disabled = true;
                unreadBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Elaborazione...';
            }

            // Chiamata AJAX per spostare l'email
            const userSelect = document.getElementById('userSelect');
            const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;

            // ✅ CORREZIONE FONDAMENTALE: Usa currentEmailFile invece di 'file'

            const response = await fetch('mark_as_unread.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                // ✅ CORREZIONE FONDAMENTALE: Usa currentEmailFile invece di 'file'
                body: `user=${encodeURIComponent(requestUser)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder)}`
            });

            const data = await response.json().catch(() => {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            });

            if (data.success) {
                showToast('✅ Email contrassegnata come "Non letta"', 'success');

                // Ricarica la lista email corrente
                setTimeout(() => {
                    loadEmails(currentPage);
                    const emailDetail = document.getElementById('emailDetail');
                    const emptyState = document.getElementById('emptyState');
                    const contentTitle = document.getElementById('contentTitle');
                    if (emailDetail && emptyState && contentTitle) {
                        emailDetail.style.display = 'none';
                        emptyState.style.display = 'block';
                        contentTitle.textContent = 'Seleziona un\'email per visualizzarla';
                    }
                }, 500);
            } else {
                throw new Error(data.error || 'Errore sconosciuto');
            }
        } catch (error) {
            showToast(`Errore: ${error.message || 'Impossibile contrassegnare email'}`, 'error');
        } finally {
            // Riabilita pulsante
            const unreadBtn = document.querySelector('.btn-unread');
            if (unreadBtn) {
                unreadBtn.disabled = false;
                unreadBtn.innerHTML = '<i class="fas fa-envelope-open"></i> Non letto';
            }
        }
    }

    // ===================================================================
    // ==================== NUOVE EMAIL - GESTIONE MODALE ====================
    document.addEventListener('DOMContentLoaded', function() {
        // === BOTTONE "NUOVE EMAIL" NELL'HEADER ===
        const newEmailsBtn = document.getElementById('newEmailsBtn');
        if (newEmailsBtn) {
            newEmailsBtn.addEventListener('click', showNewEmails);
        }
        
        // === GESTIONE MODALE NUOVE EMAIL (UNICO EVENT LISTENER) ===
        const modal = document.getElementById('newEmailsModal');
        if (modal) {
            // 🎯 BOTTONE MINIMIZZA: Gestione ESCLUSIVA con stopPropagation
            const minimizeBtn = modal.querySelector('.modal-btn.minimize');
            if (minimizeBtn) {
                minimizeBtn.addEventListener('click', function(e) {
                    e.stopPropagation(); // BLOCCA il bubbling verso il modale
                    toggleNewEmailsModal();
                });
            }
            
            // 🎯 BOTTONE CHIUDI: Gestione ESCLUSIVA con stopPropagation
            const closeBtn = modal.querySelector('.modal-btn.close');
            if (closeBtn) {
                closeBtn.addEventListener('click', function(e) {
                    e.stopPropagation(); // BLOCCA il bubbling verso il modale
                    closeNewEmailsModal();
                });
            }
            
            // 🎯 CLICK SUL MODALE (solo per sfondo scuro o stato minimizzato)
            modal.addEventListener('click', function(e) {
                // SE MINIMIZZATO: Espandi su QUALSIASI click (tranne close button, già gestito)
                if (this.classList.contains('minimized')) {
                    toggleNewEmailsModal();
                    return;
                }
                
                // SE NON MINIMIZZATO: Chiudi SOLO se click sullo sfondo scuro (non sul contenuto)
                if (e.target === this) {
                    closeNewEmailsModal();
                }
            });
        }
        
        // === AGGIORNAMENTO BADGE ===
        updateNewEmailsBadge();
        setInterval(updateNewEmailsBadge, 30000);
    });
    
    /**
     * APRI il modale (stato normale)
     */
    function showNewEmails() {
        const modal = document.getElementById('newEmailsModal');
        if (!modal) return;
        
        // 1. Rimuovi stato minimizzato
        modal.classList.remove('minimized');
        
        // 2. Mostra modale (rimuove display:none)
        modal.style.display = 'flex';
        
        // 3. Carica dati
        loadNewEmails();
        
    }

    /**
     * TOGGLE minimizza/espandi - VERSIONE DEBUGGATA
     */
    function toggleNewEmailsModal() {
        let modal = document.getElementById('newEmailsModal');
        
        if (!modal) {
            alert('Errore: Modale non trovato. Ricaricare la pagina.');
            return;
        }
        
        const isMinimized = modal.classList.contains('minimized');
        
        if (isMinimized) {
            // ========== ESPANDI da minimizzato ==========
            
            // Rimuovi classe minimizzato
            modal.classList.remove('minimized');
            
            // Forza display:flex per sicurezza
            modal.style.display = 'flex';

            // Ricarica dati se necessario
            if (newEmailsData.length === 0) {
                loadNewEmails();
            }
            
        } else {
            // ========== MINIMIZZA ==========
            
            // Aggiungi classe minimizzato
            modal.classList.add('minimized');
            
            // Mantieni display:flex (gestito dal CSS con !important)
            modal.style.display = 'flex';

            // Aggiorna badge nel titolo
            updateMinimizedBadge(newEmailsData.length);
        }
    }

    /**
     * CHIUDI il modale completamente
     */
    function closeNewEmailsModal() {
        const modal = document.getElementById('newEmailsModal');
        if (!modal) return;
        
        modal.style.display = 'none';
        newEmailsModalOpen = false;
    }

    /**
     * Carica le email non lette da tutti gli utenti
     */
    function loadNewEmails() {
        const contentDiv = document.getElementById('newEmailsContent');
        if (!contentDiv) return;
        
        contentDiv.innerHTML = `
            <div class="loading-new-emails">
                <i class="fas fa-spinner fa-spin fa-3x"></i>
                <p style="margin-top: 15px; font-size: 1.1em;">Caricamento email non lette...</p>
            </div>
        `;
        
        fetch('get_all_new_emails.php')
            .then(response => {
                if (!response.ok) throw new Error('Errore HTTP: ' + response.status);
                return response.json();
            })
            .then(data => {
                newEmailsData = data.emails || [];
                
                if (newEmailsData.length === 0) {
                    contentDiv.innerHTML = `
                        <div class="empty-state-new-emails">
                            <i class="fas fa-inbox"></i>
                            <h3>Nessuna email non letta</h3>
                            <p style="margin-top: 10px; color: #6b7280;">
                                Tutte le email sono state lette
                            </p>
                            <button onclick="loadNewEmails()" class="retry-btn" style="margin-top: 20px;">
                                <i class="fas fa-redo"></i> Aggiorna
                            </button>
                        </div>
                    `;
                } else {
                    // ✅ GENERAZIONE TABELLA CON 5 COLONNE (2 COLONNE AZIONI SEPARATE)
                    let html = `
                        <table class="new-emails-table">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-at"></i> Account</th>
                                    <th><i class="fas fa-envelope"></i> Mittente / Oggetto</th>
                                    <th><i class="fas fa-clock"></i> Data/Ora</th>
                                    <th><i class="fas fa-eye"></i> Visualizza</th>
                                    <th><i class="fas fa-trash"></i> Elimina</th>
                                </tr>
                            </thead>
                            <tbody>
                    `;
                    
                    newEmailsData.forEach((email, index) => {
                        html += `
                            <tr>
                                <td class="user-col">${escapeHtml(email.account_name || email.user)}</td>
                                <td class="sender-col">
                                    <a href="javascript:void(0)" class="email-open-link"
                                       data-index="${index}"
                                       title="Apri e visualizza questa email">
                                        <span class="sender-name">${escapeHtml(email.sender)}</span>
                                        <div class="subject-preview">${escapeHtml(email.subject)}</div>
                                    </a>
                                </td>
                                <td class="date-col">${escapeHtml(email.date)}</td>
                                <td class="action-col action-view">
                                    <button class="action-btn btn-view"
                                            data-index="${index}"
                                            title="Visualizza email">
                                        <i class="fas fa-eye"></i> <span class="btn-text">Apri</span>
                                    </button>
                                </td>
                                <td class="action-col action-delete">
                                    <button class="action-btn btn-delete"
                                            data-index="${index}"
                                            title="Elimina email">
                                        <i class="fas fa-trash"></i> <span class="btn-text">Cancella</span>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    
                    html += `
                            </tbody>
                        </table>
                        <div style="margin-top: 15px; padding: 15px; background: #f8f9ff; border-radius: 10px; text-align: center;">
                            <strong>${newEmailsData.length}</strong> email non lette da 
                            <strong>${[...new Set(newEmailsData.map(e => e.user))].length}</strong> account
                        </div>
                    `;
                    
                    contentDiv.innerHTML = html;

                    // ✅ Collega gli eventi via data-index invece di onclick con stringhe
                    // interpolate: mittente/oggetto non fidati potrebbero contenere
                    // apici o caratteri HTML e rompere gli attributi inline.
                    contentDiv.querySelectorAll('.email-open-link, .btn-view').forEach(el => {
                        el.addEventListener('click', function() {
                            const email = newEmailsData[parseInt(this.getAttribute('data-index'), 10)];
                            if (email) viewNewEmail(email.user, email.file);
                        });
                    });
                    contentDiv.querySelectorAll('.btn-delete').forEach(el => {
                        el.addEventListener('click', function() {
                            const index = parseInt(this.getAttribute('data-index'), 10);
                            const email = newEmailsData[index];
                            if (email) deleteNewEmail(index, email.user, email.file);
                        });
                    });

                    // Aggiorna badge
                    updateNewEmailsBadgeCount(newEmailsData.length);
                }
            })
            .catch(error => {
                contentDiv.innerHTML = `
                    <div class="empty-state-new-emails">
                        <i class="fas fa-exclamation-triangle" style="color: #ef4444;"></i>
                        <h3>Errore nel caricamento</h3>
                        <p style="margin-top: 10px; color: #6b7280;">
                            ${error.message || 'Impossibile recuperare le email non lette'}
                        </p>
                        <button onclick="loadNewEmails()" class="retry-btn" style="margin-top: 20px;">
                            <i class="fas fa-redo"></i> Riprova
                        </button>
                    </div>
                `;
            });
    }

    /**
     * Visualizza un'email non letta
     */
    function viewNewEmail(user, file) {

        // 1. Le email non lette vivono sempre nella cartella Inbox
        document.querySelectorAll('.folder-btn').forEach(btn => btn.classList.remove('active'));
        const inboxBtn = document.querySelector('.folder-btn[data-folder="inbox"]');
        if (inboxBtn) inboxBtn.classList.add('active');
        currentFolder = 'inbox';
        emailFolder = 'inbox';

        // 2. Seleziona l'utente nel dropdown (letto da showEmailDetails/reply/download ecc.)
        const userSelect = document.getElementById('userSelect');
        if (userSelect) {
            for (let i = 0; i < userSelect.options.length; i++) {
                if (userSelect.options[i].value === user) {
                    userSelect.selectedIndex = i;
                    break;
                }
            }
        }
        currentUser = user;

        // 3. Aggiorna la sidebar in background, solo per coerenza visiva: la Inbox
        //    può contenere migliaia di email paginate (50 per pagina), quindi cercare
        //    l'email cliccata tra gli elementi già renderizzati nella pagina corrente
        //    non è mai affidabile. L'apertura sotto NON dipende da questo.
        loadEmails(1, 'inbox');

        // 4. Apri direttamente il dettaglio email riusando showEmailDetails(), la stessa
        //    funzione chiamata quando si clicca un item nella sidebar. Le passiamo un
        //    elemento "finto" (mai inserito nel DOM) con classe email-new, così scatta
        //    anche lo spostamento new→cur che segna l'email come letta.
        const fakeElement = document.createElement('div');
        fakeElement.className = 'email-item email-new';
        fakeElement.setAttribute('data-file', file);
        fakeElement.setAttribute('data-folder', 'inbox');

        // 5. Minimizza il modale delle nuove email non appena il caricamento è concluso,
        //    per mostrare subito l'email aperta.
        showEmailDetails(file, fakeElement).then(() => toggleNewEmailsModal());
    }

    /**
     * Elimina un'email non letta
     */
    function deleteNewEmail(index, user, file) {
        if (!confirm(`Spostare l'email nel Cestino?\n\nAccount: ${newEmailsData[index].account_name || user}\nMittente: ${newEmailsData[index].sender}`)) {
            return;
        }
        
        fetch('delete_new_email.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `user=${encodeURIComponent(user)}&file=${encodeURIComponent(file)}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('✅ Email spostata nel Cestino', 'success');
                
                // Rimuovi dalla lista locale
                newEmailsData.splice(index, 1);
                
                // Ricarica la tabella dal server (dati sempre coerenti)
                loadNewEmails();
                
                // Aggiorna badge
                updateNewEmailsBadgeCount(newEmailsData.length);
                
            } else {
                throw new Error(data.error || 'Errore sconosciuto');
            }
        })
        .catch(error => {
            showToast(`Errore: ${error.message}`, 'error');
        });
    }

    /**
     * Aggiorna il badge contatore
     */
    function updateNewEmailsBadge() {
        fetch('get_all_new_emails.php')
            .then(response => response.json())
            .then(data => {
                const count = data.total || 0;
                updateNewEmailsBadgeCount(count);
            })
            .catch(() => {});
    }
    /**
     * Aggiorna badge nel titolo quando minimizzato
     */
    function updateMinimizedBadge(count) {
        const modal = document.getElementById('newEmailsModal');
        if (modal && modal.classList.contains('minimized')) {
            const headerTitle = modal.querySelector('.modal-header h2');
            if (headerTitle) {
                headerTitle.setAttribute('data-badge', count > 0 ? count : '0');
            }
        }
    }
    /**
     * Aggiorna contatore badge nel bottone header
     */
    function updateNewEmailsBadgeCount(count) {
        const badge = document.getElementById('newEmailsBadge');
        if (badge) {
            badge.textContent = count;
            badge.style.display = count > 0 ? 'inline-block' : 'none';
            
            if (count > 0) {
                badge.style.animation = 'none';
                setTimeout(() => {
                    badge.style.animation = 'pulseBadge 2s infinite';
                }, 10);
            }
        }
        
        // Aggiorna anche badge nel modale minimizzato se visibile
        updateMinimizedBadge(count);
    }
    // ===================================================================
    // ==================== FUNZIONE DI PULIZIA ALLEGATI ====================
    /**
     * Pulisce completamente tutti gli allegati dalla cache e dalla UI
     * Deve essere chiamata DOPO ogni invio email riuscito
     */
    /**
     * Pulisce COMPLETAMENTE gli allegati dalla UI e dal form
     * Usa il metodo nativo reset() del form per garantire pulizia sicura
     */
    function clearAttachments() {
        
        // ✅ METODO SICURO: Resetta l'INTERO form (pulisce anche i campi file)
        const composeForm = document.getElementById('composeForm');
        if (composeForm) {
            composeForm.reset(); // ✅ RESET NATIVO - Funziona in TUTTI i browser
        }
        
        // ✅ Pulisci anteprima allegati nella UI (reset non la tocca)
        const attachmentPreview = document.getElementById('attachmentPreview');
        if (attachmentPreview) {
            attachmentPreview.innerHTML = '';
        }
        
        // ✅ Pulisci contatore allegati
        const attachmentsCount = document.getElementById('attachmentsCount');
        if (attachmentsCount) {
            attachmentsCount.textContent = '0';
        }
        
        // ✅ Rimuovi classe "has-attachments" dal form
        if (composeForm) {
            composeForm.classList.remove('has-attachments');
        }
        
        // ✅ Resetta variabili globali (se esistono)
        if (typeof window.currentAttachments !== 'undefined') {
            window.currentAttachments = [];
        }
        
        // ✅ Resetta stato drag & drop
        const dropZone = document.querySelector('.drop-zone');
        if (dropZone) {
            dropZone.classList.remove('drag-over');
        }
        
        // ✅ RESETTA selectedFiles (aggiunto per sicurezza)
        if (typeof selectedFiles !== 'undefined') {
            selectedFiles = [];
        }
        
    }
    // ===================================================================   
    
    // ==================== FUNZIONE SWITCH TAB CORPO EMAIL ====================
    /**
     * Cambia scheda tra HTML e Testo nella visualizzazione dettagli email
     * @param {string} tabName - 'html' o 'text'
     */
    function switchTab(tabName) {
        
        // Rimuovi classe active da tutti i pulsanti tab
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Rimuovi classe active da tutti i contenuti tab
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        
        // Attiva la scheda selezionata
        if (tabName === 'html') {
            // Attiva pulsante HTML
            const htmlBtn = document.querySelector('.tab-btn:first-child');
            if (htmlBtn) htmlBtn.classList.add('active');
            
            // Attiva contenuto HTML
            const htmlContent = document.getElementById('htmlTab');
            if (htmlContent) htmlContent.classList.add('active');
            
        } else if (tabName === 'text') {
            // Attiva pulsante Testo
            const textBtn = document.querySelector('.tab-btn:last-child');
            if (textBtn) textBtn.classList.add('active');
            
            // Attiva contenuto Testo
            const textContent = document.getElementById('textTab');
            if (textContent) textContent.classList.add('active');
            
        } else {
            // Fallback: attiva HTML come default
            document.querySelector('.tab-btn:first-child')?.classList.add('active');
            document.getElementById('htmlTab')?.classList.add('active');
        }
        
        // Aggiorna accessibilità
        document.querySelectorAll('.tab-btn').forEach((btn, index) => {
            btn.setAttribute('aria-selected', 
                (tabName === 'html' && index === 0) || 
                (tabName === 'text' && index === 1)
            );
        });
    }
    // ===================================================================

    /**
     * Scarica allegato - VERSIONE CORRETTA
     * Correzioni applicate:
     * ✅ IGNORA completamente il parametro 'user' passato dal bottone
     * ✅ Legge SEMPRE il valore corrente dal dropdown (#userSelect)
     * ✅ Logging esplicito per debug immediato
     * ✅ Gestione errori migliorata con suggerimenti pratici
     */
    function downloadAttachmentViaFetch(passedUser, passedFile, passedIndex, passedFolder, filename) {
        // ✅ IGNORA passedUser e leggi SEMPRE dal dropdown (fonte di verità)
        const userSelect = document.getElementById('userSelect');
        const requestUser = (userSelect && userSelect.value.trim()) ? userSelect.value.trim() : currentUser;
        
        // Decodifica parametri passati (erano codificati nel bottone)
        const file = decodeURIComponent(passedFile);
        const index = decodeURIComponent(passedIndex);
        const folder = decodeURIComponent(passedFolder);
        
        // ✅ LOGGING ESPPLICITO PER DEBUG
        // Costruisci URL con parametri corretti
        const params = new URLSearchParams({
            user: requestUser,  // ✅ SEMPRE dal dropdown
            file: file,
            index: index,
            folder: folder,
            disposition: 'attachment'
        });
        
        const url = `get_attachment_content.php?${params.toString()}`;
        
        // Fetch con gestione errori esplicita
        fetch(url, {
            method: 'GET',
            credentials: 'include',
            headers: { 'Accept': '*/*' }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.blob();
        })
        .then(blob => {
            // Crea URL temporaneo e triggera download
            const downloadUrl = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = downloadUrl;
            a.download = filename || 'allegato';
            a.style.display = 'none';
            document.body.appendChild(a);
            a.click();
            
            // Pulizia
            setTimeout(() => {
                document.body.removeChild(a);
                window.URL.revokeObjectURL(downloadUrl);
            }, 100);
            
            showToast(`Download completato: ${filename}`, 'success');
        })
        .catch(error => {
            
            // Messaggio utente contestuale
            let errorMsg = `Impossibile scaricare "${filename}"`;
            if (error.message.includes('404')) {
                errorMsg += '\n\nFile non trovato. Verifica:\n1. L\'email esiste ancora\n2. L\'utente selezionato è corretto\n3. La cartella è accessibile';
            } else if (error.message.includes('403')) {
                errorMsg += '\n\nPermesso negato. Contatta l\'amministratore.';
            }
            
            showToast(`Errore: ${error.message}`, 'error');
            alert(errorMsg);
        });
    }

    </script>
</body>
</html>
