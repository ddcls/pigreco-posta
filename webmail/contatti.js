/*
 * PiGreco Posta
 * Copyright (C) 2026 Criscione Enzo
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Questo programma è software libero: puoi ridistribuirlo e/o modificarlo
 * secondo i termini della GNU Affero General Public License come pubblicata
 * dalla Free Software Foundation, versione 3 della licenza o (a tua scelta)
 * qualsiasi versione successiva.
 *
 * Questo programma è distribuito nella speranza che sia utile, ma SENZA
 * ALCUNA GARANZIA; senza neppure la garanzia implicita di COMMERCIABILITÀ
 * o di IDONEITÀ PER UN PARTICOLARE SCOPO. Vedi la GNU Affero General
 * Public License per i dettagli.
 *
 * Dovresti aver ricevuto una copia della GNU Affero General Public License
 * insieme a questo programma (file LICENSE). In caso contrario, vedi
 * <https://www.gnu.org/licenses/>.
 */

/**
 * Contatti: la rubrica della webmail.
 *
 * Due rubriche possibili, con gli stessi campi e lo stesso codice:
 * - webmail da sola (ARCHIVIO_URL vuoto): la rubrica della webmail, tabella
 *   contatti (contatti.php, lib/ContattiService.php), una sola in comune per
 *   tutti gli utenti, che la vedono e la modificano tutti;
 * - webmail collegata a PiGreco Archivio: la rubrica è l'agenda telefonica
 *   dell'archivio (tabella agenda_contatti), letta e modificata con le sue API
 *   (/api/agenda) e la sessione dell'archivio, come fa la finestra "Archivia"
 *   (archivio.js, ArchiviaEmail.api). Valgono le regole dell'agenda: il
 *   consultore consulta, l'operatore modifica i contatti che ha creato lui,
 *   l'amministratore tutti.
 *
 * - Menu ⚙️ › Contatti: finestra con ricerca, categorie, nuovo, modifica,
 *   elimina, esporta CSV e "✉️ Scrivi" (nuova email già indirizzata); nella
 *   rubrica della webmail anche "Importa" da CSV (prima un controllo che non
 *   scrive niente, poi la conferma).
 * - Nuova email: mentre si scrive in "A", "Cc" o "Ccn" compaiono dal primo
 *   carattere i suggerimenti dalla rubrica, i più vicini prima, e il primo
 *   indirizzo che inizia così viene completato nel campo (con l'archivio solo
 *   se si è già collegati: non chiede l'accesso).
 * - Lettura: il pulsante accanto al mittente lo aggiunge alla rubrica; se il
 *   mittente non c'è, una riga sopra l'email propone "Aggiungi / No, grazie".
 * - Dopo l'invio: propone di aggiungere i destinatari che non sono in rubrica.
 *   Gli indirizzi rifiutati non vengono più proposti (localStorage del browser).
 */

const ContattiRubrica = {
    CATEGORIE: ['Cliente', 'Fornitore', 'Collega', 'Partner', 'Altro'],
    contatti: [],
    caricati: 0,        // momento dell'ultimo caricamento (per i suggerimenti)
    senzaSessione: 0,   // ultimo controllo senza accesso all'archivio
    overlay: null,
    filtro: '',
    categoria: '',

    /** true = rubrica dell'archivio (agenda), false = rubrica della webmail (contatti.php) */
    archivio() {
        return typeof ARCHIVIO_URL !== 'undefined' && String(ARCHIVIO_URL || '').trim() !== '';
    },

    /** Chiamata a contatti.php (rubrica della webmail) */
    async locale(azione = '', dati = null) {
        const opzioni = { credentials: 'same-origin' };
        if (dati) {
            const corpo = new FormData();
            corpo.append('azione', azione);
            Object.entries(dati).forEach(([k, v]) => corpo.append(k, v ?? ''));
            opzioni.method = 'POST';
            opzioni.body = corpo;
        }
        const r = await fetch('contatti.php', opzioni);
        const risposta = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
        if (!risposta.success) throw new Error(risposta.error || 'Operazione non riuscita');
        return risposta;
    },

    /** Nuovo contatto (contatto = null) o modifica, nella rubrica in uso */
    async scrivi(contatto, dati) {
        if (!this.archivio()) {
            await this.locale('salva', { ...dati, id: contatto ? Number(contatto.id) : '' });
            return;
        }
        await ArchiviaEmail.api(contatto ? `/agenda/${Number(contatto.id)}` : '/agenda', {
            method: contatto ? 'PUT' : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(dati),
        });
    },

    async cancella(contatto) {
        if (!this.archivio()) {
            await this.locale('elimina', { id: Number(contatto.id) });
            return;
        }
        await ArchiviaEmail.api(`/agenda/${Number(contatto.id)}`, { method: 'DELETE' });
    },

    esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    nomeCompleto(c) {
        return [c.nome, c.cognome].filter(Boolean).join(' ') || c.azienda || c.email || '(senza nome)';
    },

    iniziali(c) {
        const n = (c.nome || '').trim(), k = (c.cognome || '').trim();
        const s = (n[0] || '') + (k[0] || '') || (c.azienda || c.email || '?')[0];
        return s.toUpperCase();
    },

    /**
     * Può modificare/eliminare il contatto: nella rubrica della webmail tutti,
     * nell'agenda dell'archivio le regole di AgendaController::canWrite
     */
    puoModificare(c) {
        if (!this.archivio()) return true;
        const u = ArchiviaEmail.utente;
        if (!u || u.ruolo === 'consultore') return false;
        if (!c) return true;
        if (u.ruolo === 'admin') return true;
        return Number(c.utente_creatore_id) === Number(u.id);
    },

    async carica() {
        const dati = this.archivio() ? await ArchiviaEmail.api('/agenda') : await this.locale();
        this.contatti = dati.contatti || [];
        this.caricati = Date.now();
    },

    // ---------------------------------------------------------------- finestra

    async apri(nuovo = null) {
        this.costruisci();
        this.stato(this.archivio() ? '<i class="fas fa-spinner fa-spin"></i> Collegamento all\'archivio...'
            : '<i class="fas fa-spinner fa-spin"></i> Caricamento dei contatti...');
        try {
            if (this.archivio() && !(await ArchiviaEmail.sessioneArchivio())) {
                this.mostraLogin(nuovo);
                return;
            }
            await this.carica();
            this.mostraElenco();
            if (nuovo) this.mostraModulo(null, nuovo);
        } catch (e) {
            this.stato(`<div class="archivia-errore"><i class="fas fa-exclamation-triangle"></i> ${this.esc(e.message)}</div>`);
        }
    },

    chiudi() {
        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }
    },

    costruisci() {
        this.chiudi();
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay contatti-overlay';
        overlay.innerHTML = `
            <div class="compose-modal contatti-modal">
                <div class="compose-modal-header">
                    <h3><i class="fas fa-address-book"></i> Contatti</h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <div class="contatti-corpo"></div>
            </div>`;
        overlay.querySelector('.btn-close-compose').addEventListener('click', () => this.chiudi());
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.chiudi(); });
        document.body.appendChild(overlay);
        this.overlay = overlay;
    },

    corpo() { return this.overlay.querySelector('.contatti-corpo'); },

    stato(html) {
        this.corpo().innerHTML = `<div class="archivia-stato">${html}</div>`;
    },

    mostraLogin(nuovo, errore = '') {
        this.corpo().innerHTML = `
            <div class="contatti-login">
                <p class="archivia-nota">La rubrica è quella di <strong>PiGreco Archivio</strong>: accedi con il tuo
                utente <strong>dell'archivio</strong> (non quello della webmail).</p>
                ${errore ? `<div class="archivia-errore">${this.esc(errore)}</div>` : ''}
                <form class="archivia-login">
                    <label>Utente<input type="text" name="username" autocomplete="username" required></label>
                    <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                    <div class="contatti-azioni-modulo">
                        <button type="button" class="archivia-btn-secondario" data-azione="annulla">Annulla</button>
                        <button type="submit" class="archivia-btn-primario"><i class="fas fa-sign-in-alt"></i> Accedi</button>
                    </div>
                </form>
            </div>`;
        const form = this.corpo().querySelector('form');
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            try {
                const dati = await ArchiviaEmail.api('/auth', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username: form.username.value, password: form.password.value }),
                });
                ArchiviaEmail.csrf = dati.csrf_token || null;
                ArchiviaEmail.utente = dati.user || null;
                this.stato('<i class="fas fa-spinner fa-spin"></i> Caricamento dei contatti...');
                await this.carica();
                this.mostraElenco();
                if (nuovo) this.mostraModulo(null, nuovo);
            } catch (e) {
                this.mostraLogin(nuovo, e.message);
            }
        });
        form.querySelector('[data-azione="annulla"]').addEventListener('click', () => this.chiudi());
        form.username.focus();
    },

    mostraElenco() {
        const puoCreare = this.puoModificare(null);
        this.corpo().innerHTML = `
            <div class="contatti-barra">
                <input type="search" class="contatti-cerca" placeholder="Cerca per nome, azienda, email, telefono…" value="${this.esc(this.filtro)}">
                <select class="contatti-categoria">
                    <option value="">Tutte le categorie</option>
                    ${this.CATEGORIE.map(c => `<option ${c === this.categoria ? 'selected' : ''}>${c}</option>`).join('')}
                </select>
                ${puoCreare ? '<button type="button" class="archivia-btn-primario" data-azione="nuovo"><i class="fas fa-user-plus"></i> Nuovo</button>' : ''}
                ${this.archivio() ? '' : '<button type="button" class="archivia-btn-secondario" data-azione="importa" title="Aggiungi contatti da un file CSV"><i class="fas fa-file-import"></i> Importa</button>'}
                <a class="archivia-btn-secondario" href="${this.archivio() ? '/api/agenda/export' : 'contatti.php?azione=esporta'}" title="Scarica la rubrica in formato CSV"><i class="fas fa-file-export"></i> CSV</a>
            </div>
            <div class="contatti-conteggio"></div>
            <div class="contatti-elenco"></div>
            <p class="contatti-nota">${this.archivio()
                ? 'È la stessa rubrica dell\'agenda telefonica di PiGreco Archivio.' + (puoCreare ? '' : ' Il tuo ruolo nell\'archivio permette solo di consultarla.')
                : 'La rubrica è in comune: tutti gli utenti della webmail la vedono e la modificano.'}</p>`;
        const cerca = this.corpo().querySelector('.contatti-cerca');
        cerca.addEventListener('input', () => { this.filtro = cerca.value; this.disegnaRighe(); });
        this.corpo().querySelector('.contatti-categoria').addEventListener('change', (e) => { this.categoria = e.target.value; this.disegnaRighe(); });
        const nuovo = this.corpo().querySelector('[data-azione="nuovo"]');
        if (nuovo) nuovo.addEventListener('click', () => this.mostraModulo(null));
        this.corpo().querySelector('[data-azione="importa"]')?.addEventListener('click', () => this.mostraImporta());
        this.disegnaRighe();
        cerca.focus();
    },

    disegnaRighe() {
        const q = this.filtro.trim().toLowerCase();
        const elenco = this.contatti.filter(c =>
            (!this.categoria || c.categoria === this.categoria) &&
            (!q || [c.nome, c.cognome, c.azienda, c.email, c.telefono].some(v => String(v || '').toLowerCase().includes(q))));
        this.corpo().querySelector('.contatti-conteggio').textContent =
            `${elenco.length} ${elenco.length === 1 ? 'contatto' : 'contatti'}` + (elenco.length !== this.contatti.length ? ` su ${this.contatti.length}` : '');
        const box = this.corpo().querySelector('.contatti-elenco');
        if (!elenco.length) {
            box.innerHTML = `<div class="archivia-stato">${this.contatti.length ? 'Nessun contatto corrisponde alla ricerca.' : 'La rubrica è vuota.'}</div>`;
            return;
        }
        box.innerHTML = elenco.map(c => `
            <div class="contatto-riga" data-id="${Number(c.id)}">
                <div class="contatto-avatar">${this.esc(this.iniziali(c))}</div>
                <div class="contatto-dati">
                    <div class="contatto-nome">${this.esc(this.nomeCompleto(c))}
                        <span class="contatto-categoria">${this.esc(c.categoria || 'Altro')}</span></div>
                    ${c.azienda && (c.nome || c.cognome) ? `<div class="contatto-azienda">${this.esc(c.azienda)}</div>` : ''}
                    <div class="contatto-recapiti">
                        ${c.email ? `<span><i class="fas fa-envelope"></i> ${this.esc(c.email)}</span>` : ''}
                        ${c.telefono ? `<span><i class="fas fa-phone"></i> ${this.esc(c.telefono)}</span>` : ''}
                    </div>
                </div>
                <div class="contatto-azioni">
                    ${c.email ? '<button type="button" class="archivia-btn-primario" data-azione="scrivi" title="Nuova email a questo contatto"><i class="fas fa-pen"></i> Scrivi</button>' : ''}
                    ${this.puoModificare(c) ? `
                        <button type="button" class="archivia-btn-secondario" data-azione="modifica" title="Modifica"><i class="fas fa-edit"></i></button>
                        <button type="button" class="archivia-btn-secondario" data-azione="elimina" title="Elimina"><i class="fas fa-trash"></i></button>` : ''}
                </div>
            </div>`).join('');
        box.querySelectorAll('.contatto-riga').forEach(riga => {
            const c = this.contatti.find(x => Number(x.id) === Number(riga.dataset.id));
            riga.querySelector('[data-azione="scrivi"]')?.addEventListener('click', () => {
                this.chiudi();
                composeToEmail(c.email, 'Destinatario');
            });
            riga.querySelector('[data-azione="modifica"]')?.addEventListener('click', () => this.mostraModulo(c));
            riga.querySelector('[data-azione="elimina"]')?.addEventListener('click', () => this.elimina(c));
        });
    },

    /** Modulo nuovo/modifica. "precompilato" = dati di partenza per un nuovo contatto */
    mostraModulo(contatto, precompilato = {}) {
        const c = contatto || { categoria: 'Altro', ...precompilato };
        const campo = (nome, etichetta, tipo = 'text', extra = '') =>
            `<label>${etichetta}<input type="${tipo}" name="${nome}" value="${this.esc(c[nome] || '')}" ${extra}></label>`;
        this.corpo().innerHTML = `
            <form class="contatti-modulo">
                <h4>${contatto ? 'Modifica contatto' : 'Nuovo contatto'}</h4>
                <div class="contatti-griglia">
                    ${campo('nome', 'Nome', 'text', 'maxlength="100"')}
                    ${campo('cognome', 'Cognome', 'text', 'maxlength="100"')}
                    ${campo('azienda', 'Azienda', 'text', 'maxlength="150"')}
                    <label>Categoria<select name="categoria">
                        ${this.CATEGORIE.map(k => `<option ${k === (c.categoria || 'Altro') ? 'selected' : ''}>${k}</option>`).join('')}
                    </select></label>
                    ${campo('email', 'Email', 'email', 'maxlength="150"')}
                    ${campo('telefono', 'Telefono', 'tel', 'maxlength="50"')}
                    ${campo('sito_web', 'Sito web', 'text', 'maxlength="500"')}
                </div>
                <label>Note<textarea name="note" rows="3">${this.esc(c.note || '')}</textarea></label>
                <div class="contatti-azioni-modulo">
                    <button type="button" class="archivia-btn-secondario" data-azione="indietro">Annulla</button>
                    <button type="submit" class="archivia-btn-primario"><i class="fas fa-save"></i> Salva</button>
                </div>
            </form>`;
        const form = this.corpo().querySelector('form');
        form.querySelector('[data-azione="indietro"]').addEventListener('click', () => this.mostraElenco());
        form.addEventListener('submit', (ev) => { ev.preventDefault(); this.salva(form, contatto); });
        (form.nome.value ? form.cognome : form.nome).focus();
    },

    async salva(form, contatto) {
        const dati = {};
        ['nome', 'cognome', 'azienda', 'categoria', 'email', 'telefono', 'sito_web', 'note'].forEach(k => { dati[k] = form[k].value.trim(); });
        if (!dati.nome && !dati.cognome) {
            showToast('Inserisci almeno nome o cognome', 'error');
            form.nome.focus();
            return;
        }
        if (!form.reportValidity()) return;
        const pulsante = form.querySelector('[type="submit"]');
        pulsante.disabled = true;
        try {
            await this.scrivi(contatto, dati);
            showToast(contatto ? 'Contatto aggiornato' : 'Contatto aggiunto alla rubrica', 'success');
            await this.carica();
            this.mostraElenco();
            this.aggiornaBanner();
        } catch (e) {
            pulsante.disabled = false;
            showToast('Contatto non salvato: ' + e.message, 'error');
        }
    },

    async elimina(c) {
        const condivisa = this.archivio() ? 'La rubrica è condivisa con PiGreco Archivio.' : 'La rubrica è in comune per tutti gli utenti della webmail.';
        if (!confirm(`Eliminare "${this.nomeCompleto(c)}" dalla rubrica?\n\n${condivisa}`)) return;
        try {
            await this.cancella(c);
            showToast('Contatto eliminato', 'success');
            await this.carica();
            this.disegnaRighe();
        } catch (e) {
            showToast('Contatto non eliminato: ' + e.message, 'error');
        }
    },

    // ---------------------------------------------------------------- importazione da CSV

    /** Scelta del file: si controlla subito (prova = 1), si importa dopo la conferma */
    mostraImporta(errore = '') {
        this.corpo().innerHTML = `
            <form class="contatti-modulo contatti-importa">
                <h4>Importa contatti da CSV</h4>
                <p class="archivia-nota">Va bene il CSV esportato da qui, da <strong>Outlook</strong>, da
                <strong>Google Contatti</strong> o da <strong>Thunderbird</strong>, oppure un foglio Excel salvato come
                CSV con la prima riga di intestazione, per esempio
                <code>Nome;Cognome;Azienda;Categoria;Email;Telefono;Sito web;Note</code>.
                Gli indirizzi email già in rubrica vengono saltati: nessun contatto esistente viene cambiato.</p>
                ${errore ? `<div class="archivia-errore">${this.esc(errore)}</div>` : ''}
                <label>File CSV (al massimo 5 MB)<input type="file" name="file" accept=".csv,text/csv,.txt" required></label>
                <div class="contatti-azioni-modulo">
                    <button type="button" class="archivia-btn-secondario" data-azione="indietro">Annulla</button>
                    <button type="submit" class="archivia-btn-primario"><i class="fas fa-search"></i> Controlla il file</button>
                </div>
            </form>`;
        const form = this.corpo().querySelector('form');
        form.querySelector('[data-azione="indietro"]').addEventListener('click', () => this.mostraElenco());
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const file = form.file.files[0];
            if (!file) return;
            this.stato('<i class="fas fa-spinner fa-spin"></i> Controllo del file...');
            try {
                this.mostraAnteprima(file, await this.locale('importa', { prova: '1', file }));
            } catch (e) {
                this.mostraImporta(e.message);
            }
        });
    },

    NOMI_CAMPI: { nome: 'Nome', cognome: 'Cognome', completo: 'Nome e cognome', azienda: 'Azienda', email: 'Email',
        telefono: 'Telefono', sito_web: 'Sito web', note: 'Note', categoria: 'Categoria' },

    mostraAnteprima(file, esito) {
        const colonne = Object.entries(esito.colonne || {})
            .map(([campo, nome]) => `<li><strong>${this.esc(this.NOMI_CAMPI[campo] || campo)}</strong> ← ${this.esc(nome)}</li>`).join('');
        const esempi = (esito.esempi || []).map(c => `
            <tr><td>${this.esc(this.nomeCompleto(c))}</td><td>${this.esc(c.azienda)}</td><td>${this.esc(c.email)}</td>
                <td>${this.esc(c.telefono)}</td><td>${this.esc(c.categoria)}</td></tr>`).join('');
        const errori = (esito.errori || []).map(e => `<li>riga ${Number(e.riga)}: ${this.esc(e.motivo)}</li>`).join('');
        const n = Number(esito.nuovi) || 0;
        this.corpo().innerHTML = `
            <div class="contatti-modulo contatti-importa">
                <h4>Importa contatti da CSV · ${this.esc(file.name)}</h4>
                <ul class="importa-riepilogo">
                    <li><i class="fas fa-user-plus"></i> <strong>${n}</strong> ${n === 1 ? 'contatto nuovo' : 'contatti nuovi'}</li>
                    <li><i class="fas fa-user-check"></i> <strong>${Number(esito.presenti) || 0}</strong> già in rubrica (saltati)</li>
                    ${esito.errori?.length ? `<li class="importa-errori-titolo"><i class="fas fa-exclamation-triangle"></i> <strong>${esito.errori.length}</strong> righe non valide (saltate)</li>` : ''}
                </ul>
                <details class="importa-dettagli"><summary>Colonne riconosciute</summary><ul>${colonne}</ul></details>
                ${errori ? `<details class="importa-dettagli" open><summary>Righe non valide</summary><ul>${errori}</ul></details>` : ''}
                ${esempi ? `<p class="contatti-nota">${n > 10 ? 'I primi 10 contatti nuovi:' : 'I contatti nuovi:'}</p>
                    <div class="importa-tabella"><table><thead><tr><th>Nome</th><th>Azienda</th><th>Email</th><th>Telefono</th><th>Categoria</th></tr></thead>
                    <tbody>${esempi}</tbody></table></div>` : ''}
                <div class="contatti-azioni-modulo">
                    <button type="button" class="archivia-btn-secondario" data-azione="indietro">Annulla</button>
                    ${n ? `<button type="button" class="archivia-btn-primario" data-azione="importa"><i class="fas fa-file-import"></i> Importa ${n} ${n === 1 ? 'contatto' : 'contatti'}</button>` : ''}
                </div>
            </div>`;
        this.corpo().querySelector('[data-azione="indietro"]').addEventListener('click', () => this.mostraElenco());
        this.corpo().querySelector('[data-azione="importa"]')?.addEventListener('click', async (ev) => {
            ev.currentTarget.disabled = true;
            this.stato('<i class="fas fa-spinner fa-spin"></i> Importazione dei contatti...');
            try {
                const fatto = await this.locale('importa', { prova: '0', file });
                showToast(fatto.nuovi === 1 ? 'Importato 1 contatto' : `Importati ${fatto.nuovi} contatti`, 'success');
                await this.carica();
                this.mostraElenco();
                this.aggiornaBanner();
            } catch (e) {
                this.mostraImporta('Importazione non riuscita, nessun contatto aggiunto: ' + e.message);
            }
        });
    },

    // ---------------------------------------------------------------- dalla lettura

    /** Aggiunge alla rubrica il mittente dell'email aperta ("Nome Cognome <indirizzo>") */
    aggiungiMittente() {
        const { email, nome } = this.mittente(document.getElementById('detailFrom')?.textContent) || { email: '', nome: '' };
        if (!email.includes('@')) {
            showToast('Indirizzo del mittente non disponibile', 'error');
            return;
        }
        const esistente = this.contatti.find(c => String(c.email || '').toLowerCase() === email.toLowerCase());
        if (esistente && this.caricati) {
            showToast(`${email} è già nella rubrica come ${this.nomeCompleto(esistente)}`, 'info');
            return;
        }
        const parti = nome.split(/\s+/).filter(Boolean);
        this.apri({ email, nome: parti.slice(0, -1).join(' ') || parti[0] || '', cognome: parti.length > 1 ? parti[parti.length - 1] : '' });
    },

    // ---------------------------------------------------------------- "vuoi aggiungerlo ai contatti?"

    CHIAVE_IGNORATI: 'webmail.contatti.ignorati',
    mittenteCorrente: '',
    mittenteTesto: '',
    proposta: null,

    /** Indirizzi per cui l'utente ha risposto "No, grazie" (solo in questo browser) */
    ignorati() {
        try {
            return new Set(JSON.parse(localStorage.getItem(this.CHIAVE_IGNORATI) || '[]'));
        } catch (e) {
            return new Set();
        }
    },

    ignora(indirizzi) {
        const elenco = this.ignorati();
        indirizzi.forEach(e => elenco.add(String(e).toLowerCase()));
        try {
            localStorage.setItem(this.CHIAVE_IGNORATI, JSON.stringify([...elenco].slice(-1000)));
        } catch (e) { /* senza localStorage la domanda tornerà alla prossima email */ }
    },

    /** "Mario Rossi <m@x.it>, b@y.it; …" → [{ email, nome }] */
    indirizzi(testo) {
        return String(testo || '').split(/[,;]/).map(parte => {
            const m = parte.match(/^\s*"?([^"<]*?)"?\s*<([^>]+)>\s*$/);
            const email = (m ? m[2] : parte).trim();
            return { email, nome: (m ? m[1] : '').trim() };
        }).filter(x => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(x.email));
    },

    /**
     * Il mittente di un'email ("Nome <indirizzo>") → { email, nome }. Nella busta
     * di una PEC il mittente è il gestore ("Per conto di: x@y" <posta-certificata@…>):
     * la persona da mettere in rubrica è x@y.
     */
    mittente(testo) {
        const [m] = this.indirizzi(testo);
        if (!m) return null;
        const perConto = m.nome.match(/^per conto di:\s*([^\s<>"]+@[^\s<>"]+\.[^\s<>"]+)/i);
        return perConto ? { email: perConto[1], nome: '' } : m;
    },

    inRubrica(email) {
        const e = String(email).toLowerCase();
        return this.contatti.some(c => String(c.email || '').toLowerCase() === e);
    },

    /** Indirizzi delle caselle della webmail (dal menu degli account) */
    mieiIndirizzi() {
        const select = document.getElementById('userSelect');
        const testo = select ? [...select.options].map(o => o.textContent).join(' ') : '';
        return new Set((testo.match(/[^\s<>,;"']+@[^\s<>,;"']+\.[A-Za-z]{2,}/g) || []).map(e => e.toLowerCase()));
    },

    /**
     * Indirizzi da non proporre: già in rubrica, propri, automatici o rifiutati.
     * posta-certificata@… è il gestore PEC (buste e ricevute), mai una persona.
     */
    daProporre(email) {
        const e = String(email).toLowerCase();
        return !this.inRubrica(e) && !this.mieiIndirizzi().has(e) && !this.ignorati().has(e) &&
            !/^(no-?reply|do-?not-?reply|mailer-daemon|postmaster|bounces?|posta-certificata)([+._-]|@)/.test(e);
    },

    /** Nome e cognome proposti: dal nome visualizzato o dalla parte prima della @ */
    nomeCognome(email, nome) {
        let parti = String(nome || '').trim().split(/\s+/).filter(Boolean);
        if (!parti.length) {
            parti = email.split('@')[0].split(/[._-]+/).filter(p => p && !/^\d+$/.test(p))
                .map(p => p.charAt(0).toUpperCase() + p.slice(1));
        }
        if (parti.length < 2) return { nome: parti[0] || '', cognome: '' };
        return { nome: parti.slice(0, -1).join(' '), cognome: parti[parti.length - 1] };
    },

    /**
     * Lettura: se il mittente non è nella rubrica, propone di aggiungerlo con una
     * riga sopra l'email. Con l'archivio solo se si è già collegati (non chiede
     * l'accesso) e con un ruolo che può creare contatti.
     */
    async proponiMittente(da) {
        const riga = document.getElementById('bannerContatto');
        if (!riga) return;
        riga.hidden = true;
        const mittente = this.mittente(da);
        this.mittenteCorrente = mittente ? mittente.email : '';
        this.mittenteTesto = mittente && mittente.nome ? `${mittente.nome} <${mittente.email}>` : this.mittenteCorrente;
        if (!mittente || !(await this.suggerimentiPronti())) return;
        this.aggiornaBanner();
    },

    aggiornaBanner() {
        const riga = document.getElementById('bannerContatto');
        if (!riga) return;
        const email = this.mittenteCorrente;
        if (!email || !this.caricati || !this.puoModificare(null) || !this.daProporre(email)) {
            riga.hidden = true;
            return;
        }
        const testo = this.mittenteTesto || email;
        riga.innerHTML = `
            <i class="fas fa-user-plus"></i>
            <span><strong>${this.esc(testo)}</strong> non è nei contatti</span>
            <button type="button" class="archivia-btn-primario" data-azione="aggiungi">Aggiungi</button>
            <button type="button" class="archivia-btn-secondario" data-azione="no">No, grazie</button>`;
        riga.querySelector('[data-azione="aggiungi"]').addEventListener('click', () => this.aggiungiMittente());
        riga.querySelector('[data-azione="no"]').addEventListener('click', () => {
            this.ignora([email]);
            riga.hidden = true;
        });
        riga.hidden = false;
    },

    /** Dopo l'invio: propone di aggiungere i destinatari che non sono nella rubrica */
    async proponiDestinatari(testo) {
        if (!(await this.suggerimentiPronti()) || !this.puoModificare(null)) return;
        const visti = new Set();
        const nuovi = this.indirizzi(testo).filter(x => {
            const e = x.email.toLowerCase();
            if (visti.has(e) || !this.daProporre(e)) return false;
            visti.add(e);
            return true;
        });
        if (nuovi.length) this.mostraProposta(nuovi);
    },

    mostraProposta(nuovi) {
        this.proposta?.remove();
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay contatti-overlay';
        overlay.innerHTML = `
            <div class="compose-modal contatti-modal contatti-proposta">
                <div class="compose-modal-header">
                    <h3><i class="fas fa-user-plus"></i> Aggiungere ai contatti?</h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <form class="contatti-corpo">
                    <p class="archivia-nota">${nuovi.length === 1 ? 'Questo indirizzo non è' : 'Questi indirizzi non sono'} nella rubrica.
                    Controlla nome e cognome; gli indirizzi non spuntati non verranno più proposti.</p>
                    ${nuovi.map((x, i) => {
                        const n = this.nomeCognome(x.email, x.nome);
                        return `
                        <div class="proposta-riga" data-i="${i}">
                            <label class="proposta-email"><input type="checkbox" checked> ${this.esc(x.email)}</label>
                            <input type="text" name="nome" placeholder="Nome" maxlength="100" value="${this.esc(n.nome)}">
                            <input type="text" name="cognome" placeholder="Cognome" maxlength="100" value="${this.esc(n.cognome)}">
                        </div>`;
                    }).join('')}
                    <div class="contatti-azioni-modulo">
                        <button type="button" class="archivia-btn-secondario" data-azione="dopo">Non ora</button>
                        <button type="submit" class="archivia-btn-primario"><i class="fas fa-save"></i> Aggiungi</button>
                    </div>
                </form>
            </div>`;
        const chiudi = () => { overlay.remove(); this.proposta = null; };
        overlay.querySelector('.btn-close-compose').addEventListener('click', chiudi);
        overlay.querySelector('[data-azione="dopo"]').addEventListener('click', chiudi);
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') chiudi(); });
        overlay.querySelectorAll('.proposta-riga').forEach(riga => {
            const spunta = riga.querySelector('[type="checkbox"]');
            spunta.addEventListener('change', () => riga.classList.toggle('esclusa', !spunta.checked));
        });
        const form = overlay.querySelector('form');
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            const scelti = [], scartati = [];
            for (const riga of form.querySelectorAll('.proposta-riga')) {
                const x = nuovi[Number(riga.dataset.i)];
                if (!riga.querySelector('[type="checkbox"]').checked) { scartati.push(x.email); continue; }
                const nome = riga.querySelector('[name="nome"]').value.trim();
                const cognome = riga.querySelector('[name="cognome"]').value.trim();
                if (!nome && !cognome) {
                    showToast(`Inserisci almeno nome o cognome per ${x.email}`, 'error');
                    riga.querySelector('[name="nome"]').focus();
                    return;
                }
                scelti.push({ nome, cognome, email: x.email, categoria: 'Altro' });
            }
            const pulsante = form.querySelector('[type="submit"]');
            pulsante.disabled = true;
            let aggiunti = 0;
            try {
                for (const dati of scelti) {
                    await this.scrivi(null, dati);
                    aggiunti++;
                }
            } catch (e) {
                showToast(`Contatto ${scelti[aggiunti].email} non salvato: ${e.message}`, 'error');
            }
            this.ignora(scartati);
            if (aggiunti) {
                showToast(aggiunti === 1 ? 'Contatto aggiunto alla rubrica' : `${aggiunti} contatti aggiunti alla rubrica`, 'success');
                await this.carica().catch(() => {});
                this.aggiornaBanner();
            }
            if (aggiunti === scelti.length) chiudi();
            else pulsante.disabled = false;
        });
        document.body.appendChild(overlay);
        this.proposta = overlay;
        form.querySelector('[type="submit"]').focus();
    },

    // ---------------------------------------------------------------- suggerimenti in "A", "Cc" e "Ccn"

    async suggerimentiPronti() {
        if (this.caricati && Date.now() - this.caricati < 5 * 60 * 1000) return true;
        if (!this.archivio()) {
            // Se la rubrica non risponde si riprova dopo un minuto, non a ogni tasto
            if (this.senzaSessione && Date.now() - this.senzaSessione < 60 * 1000) return false;
            try {
                await this.carica();
                this.senzaSessione = 0;
                return true;
            } catch (e) {
                this.senzaSessione = Date.now();
                return false;
            }
        }
        // Senza accesso all'archivio si riprova dopo un minuto, non a ogni tasto o email aperta
        if (this.senzaSessione && Date.now() - this.senzaSessione < 60 * 1000) return false;
        try {
            if (!(await ArchiviaEmail.sessioneArchivio())) {               // nessuna richiesta di accesso qui
                this.senzaSessione = Date.now();
                return false;
            }
            this.senzaSessione = 0;
            await this.carica();
            return true;
        } catch (e) {
            return false;
        }
    },

    /**
     * Contatti che corrispondono a "q", i più vicini prima: prima gli indirizzi
     * che iniziano con q, poi nome/cognome/azienda che iniziano con q, infine
     * quelli che lo contengono. "esclusi" = indirizzi già scritti nel campo.
     * Ogni voce: { c: contatto, p: 0 indirizzo / 1 nome che inizia con q, 2 contiene q }.
     */
    cerca(q, esclusi = new Set()) {
        const punteggio = (c) => {
            const email = String(c.email).toLowerCase();
            if (email.startsWith(q)) return 0;
            const nomi = [c.nome, c.cognome, c.azienda, this.nomeCompleto(c)].map(v => String(v || '').toLowerCase());
            if (nomi.some(v => v.startsWith(q) || v.split(/\s+/).some(p => p.startsWith(q)))) return 1;
            if (email.includes(q) || nomi.some(v => v.includes(q))) return 2;
            return -1;
        };
        return this.contatti
            .filter(c => c.email && !esclusi.has(String(c.email).toLowerCase()))
            .map(c => ({ c, p: punteggio(c) }))
            .filter(x => x.p >= 0)
            .sort((a, b) => a.p - b.p || String(a.c.email).localeCompare(String(b.c.email)))
            .slice(0, 8);
    },

    /**
     * Suggerimenti mentre si scrive un indirizzo: dal primo carattere, con il
     * primo indirizzo che inizia con quanto scritto completato nel campo (la parte
     * aggiunta resta selezionata: si continua a scrivere, Tab o Invio la
     * accettano, Canc la toglie; le frecce scelgono un altro suggerimento, Invio
     * prende quello evidenziato o il primo se inizia con quanto scritto). Se non
     * c'è niente da suggerire si scrive liberamente.
     * Più indirizzi separati da virgola.
     */
    collegaSuggerimenti(input) {
        if (!input || input.dataset.rubrica) return;
        input.dataset.rubrica = '1';
        input.setAttribute('autocomplete', 'off');
        const lista = document.createElement('div');
        lista.className = 'contatti-suggerimenti';
        lista.hidden = true;
        input.parentNode.style.position = 'relative';
        input.parentNode.appendChild(lista);
        let voci = [], attiva = -1;

        const parti = () => input.value.split(/[,;]/);
        const chiudi = () => { lista.hidden = true; attiva = -1; };
        const completamento = () => !lista.hidden && input.selectionStart < input.selectionEnd && input.selectionEnd === input.value.length;
        const scritto = () => completamento() ? input.value.slice(0, input.selectionStart) : input.value;
        // Mostra nel campo il resto dell'indirizzo, selezionato, se inizia con quanto scritto
        const completaCon = (c) => {
            const base = scritto();
            const q = base.split(/[,;]/).pop().trim().toLowerCase();
            const email = String(c.email);
            input.value = base;
            if (q && email.toLowerCase().startsWith(q) && email.length > q.length) {
                input.value = base + email.slice(q.length);
                input.setSelectionRange(base.length, input.value.length);
                return true;
            }
            return false;
        };
        const scegli = (c) => {
            const elenco = scritto().split(/[,;]/);
            elenco[elenco.length - 1] = ' ' + c.email;
            input.value = elenco.join(',').replace(/^\s+/, '') + ', ';
            input.setSelectionRange(input.value.length, input.value.length);
            chiudi();
            input.focus();
        };
        const evidenzia = () => lista.querySelectorAll('.contatti-suggerimento').forEach((el, i) => el.classList.toggle('attiva', i === attiva));

        input.addEventListener('input', async (ev) => {
            const valore = input.value;
            const inFondo = input.selectionStart === valore.length;
            const q = parti().pop().trim().toLowerCase();
            if (!q || !(await this.suggerimentiPronti())) { chiudi(); return; }
            if (input.value !== valore) return;          // nel frattempo si è scritto ancora
            const giaScritti = new Set(parti().slice(0, -1).map(p => p.trim().toLowerCase()).filter(Boolean));
            voci = this.cerca(q, giaScritti);
            if (!voci.length) { chiudi(); return; }
            lista.innerHTML = voci.map(({ c }) => `
                <div class="contatti-suggerimento">
                    <strong>${this.esc(this.nomeCompleto(c))}</strong>${c.azienda && (c.nome || c.cognome) ? ` · ${this.esc(c.azienda)}` : ''}
                    <span>${this.esc(c.email)}</span>
                </div>`).join('');
            lista.querySelectorAll('.contatti-suggerimento').forEach((el, i) =>
                el.addEventListener('mousedown', (e) => { e.preventDefault(); scegli(voci[i].c); }));
            attiva = -1;
            lista.hidden = false;
            // Completa nel campo solo scrivendo in fondo (non cancellando o incollando)
            if (ev.inputType === 'insertText' && inFondo && completaCon(voci[0].c)) {
                attiva = 0;
                evidenzia();
            }
        });
        input.addEventListener('keydown', (e) => {
            if (lista.hidden) return;
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                attiva = (attiva + (e.key === 'ArrowDown' ? 1 : -1) + voci.length) % voci.length;
                evidenzia();
                completaCon(voci[attiva].c);
            } else if ((e.key === 'Enter' && (attiva >= 0 || voci[0].p <= 1)) || (e.key === 'Tab' && completamento())) {
                e.preventDefault();
                scegli(voci[Math.max(attiva, 0)].c);
            } else if (e.key === 'Escape') {
                e.stopPropagation();
                if (completamento()) input.value = input.value.slice(0, input.selectionStart);
                chiudi();
            }
        });
        input.addEventListener('blur', () => setTimeout(chiudi, 150));
    },
};

// Menu ⚙️ › Contatti (index.php)
function showContatti() {
    document.getElementById('settingsMenu').classList.remove('show');
    document.removeEventListener('click', closeSettingsMenuOnClickOutside);
    ContattiRubrica.apri();
}

document.addEventListener('DOMContentLoaded', () => {
    ['composeTo', 'composeCc', 'composeBcc'].forEach(id => ContattiRubrica.collegaSuggerimenti(document.getElementById(id)));
    const da = document.getElementById('aggiungiMittenteBtn');
    if (da) {
        da.hidden = false;
        da.addEventListener('click', () => ContattiRubrica.aggiungiMittente());
    }
});
