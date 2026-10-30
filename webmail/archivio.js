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
 * Collegamento con PiGreco Archivio (attivo solo se ARCHIVIO_URL è valorizzato,
 * cioè quando la webmail gira nel container docker "webmail" dell'archivio).
 *
 * - Pulsante "Archivio": apre PiGreco Archivio.
 * - Pulsante "Archivia": manda l'email (.eml originale) all'API dell'archivio,
 *   che la conserva intatta e crea un documento per ogni allegato scelto.
 *
 * Le chiamate /api/... vanno all'archivio attraverso il container della webmail
 * (stessa origine): usano la sessione dell'archivio, quindi l'operazione è fatta
 * dall'utente dell'archivio, con i suoi permessi e i suoi faldoni visibili.
 * Se l'utente non è collegato all'archivio, la finestra chiede il suo login.
 */

/** Indirizzo dell'archivio: ":8081/" = stesso server di questa pagina, porta 8081 */
function urlArchivio() {
    const v = String(ARCHIVIO_URL || '').trim();
    if (v.startsWith(':')) {
        return window.location.protocol + '//' + window.location.hostname + v;
    }
    return v;
}

function apriArchivio() {
    // Arrivati dalla versione per telefono dell'archivio (telefono.html segna
    // "archivio.versione" nello stesso localStorage: stessa origine) si torna
    // lì nella stessa scheda, non all'ufficio 3D in una scheda nuova
    let versione = null;
    try { versione = localStorage.getItem('archivio.versione'); } catch (e) { /* niente */ }
    if (versione === 'telefono') {
        const telefono = new URL('telefono.html', new URL(urlArchivio(), window.location.href)).href;
        if (document.referrer.split('#')[0] === telefono && history.length > 1) {
            history.back();
        } else {
            window.location.href = telefono;
        }
        return;
    }
    window.open(urlArchivio(), 'archivio');
}

const ArchiviaEmail = {
    csrf: null,
    eml: null,          // Blob dell'email in archiviazione
    analisi: null,      // risposta di /api/email/analizza
    tipologie: [],
    stanze: [],         // [{id, nome}]
    armadi: [],         // [{id, nome, stanzaId, scaffali: [{id, nome, categorie: [{id, nome, faldoni: [{id, nome}]}]}]}]
    overlay: null,

    // ---------------------------------------------------------------- API archivio

    async api(percorso, opzioni = {}) {
        const o = { credentials: 'same-origin', ...opzioni, headers: { ...(opzioni.headers || {}) } };
        if (this.csrf && o.method && o.method !== 'GET') {
            o.headers['X-CSRF-Token'] = this.csrf;
        }
        const r = await fetch('/api' + percorso, o);
        let dati = {};
        try { dati = await r.json(); } catch (e) { /* risposta non JSON */ }
        if (!r.ok) {
            const errore = new Error(dati.error || ('Errore dell\'archivio (HTTP ' + r.status + ')'));
            errore.status = r.status;
            throw errore;
        }
        return dati;
    },

    /** true se c'è una sessione valida dell'archivio (e ne memorizza il token CSRF) */
    async sessioneArchivio() {
        try {
            const dati = await this.api('/auth/me');
            this.csrf = dati.csrf_token || null;
            this.utente = dati.user || null;
            return true;
        } catch (e) {
            if (e.status === 401) return false;
            throw e;
        }
    },

    // ---------------------------------------------------------------- finestra

    async apri() {
        if (!currentEmailFile) {
            showToast('Nessuna email selezionata', 'error');
            return;
        }
        this.costruisciFinestra();
        this.messaggio('<i class="fas fa-spinner fa-spin"></i> Collegamento all\'archivio...');
        try {
            if (!(await this.sessioneArchivio())) {
                this.mostraLogin();
                return;
            }
            await this.caricaEAnalizza();
        } catch (e) {
            this.errore(e.message);
        }
    },

    chiudi() {
        if (this.overlay) {
            this.overlay.remove();
            this.overlay = null;
        }
    },

    costruisciFinestra() {
        this.chiudi();
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay archivia-overlay';
        overlay.innerHTML = `
            <div class="compose-modal archivia-modal">
                <div class="compose-modal-header">
                    <h3><i class="fas fa-archive"></i> Archivia in PiGreco Archivio</h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <div class="archivia-body"></div>
                <div class="archivia-footer"></div>
            </div>`;
        overlay.querySelector('.btn-close-compose').addEventListener('click', () => this.chiudi());
        document.body.appendChild(overlay);
        this.overlay = overlay;
    },

    corpo() { return this.overlay.querySelector('.archivia-body'); },
    piede() { return this.overlay.querySelector('.archivia-footer'); },

    messaggio(html) {
        this.corpo().innerHTML = `<div class="archivia-stato">${html}</div>`;
        this.piede().innerHTML = '';
    },

    errore(testo) {
        this.messaggio(`<div class="archivia-errore"><i class="fas fa-exclamation-triangle"></i> ${escArchivio(testo)}</div>`);
        this.piede().innerHTML = '<button type="button" class="archivia-btn-secondario">Chiudi</button>';
        this.piede().querySelector('button').addEventListener('click', () => this.chiudi());
    },

    mostraLogin(erroreLogin = '') {
        this.corpo().innerHTML = `
            <p class="archivia-nota">Per archiviare accedi con il tuo utente <strong>di PiGreco Archivio</strong>
            (l'email sarà archiviata a tuo nome, nei faldoni che puoi vedere).</p>
            ${erroreLogin ? `<div class="archivia-errore">${escArchivio(erroreLogin)}</div>` : ''}
            <form class="archivia-login">
                <label>Utente<input type="text" name="username" autocomplete="username" required></label>
                <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
            </form>`;
        this.piede().innerHTML = `
            <button type="button" class="archivia-btn-secondario" data-azione="annulla">Annulla</button>
            <button type="button" class="archivia-btn-primario" data-azione="accedi"><i class="fas fa-sign-in-alt"></i> Accedi</button>`;
        const form = this.corpo().querySelector('form');
        const accedi = async () => {
            if (!form.reportValidity()) return;
            try {
                const dati = await this.api('/auth', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username: form.username.value, password: form.password.value }),
                });
                this.csrf = dati.csrf_token || null;
                this.messaggio('<i class="fas fa-spinner fa-spin"></i> Analisi dell\'email...');
                await this.caricaEAnalizza();
            } catch (e) {
                this.mostraLogin(e.message);
            }
        };
        form.addEventListener('submit', (ev) => { ev.preventDefault(); accedi(); });
        this.piede().querySelector('[data-azione="accedi"]').addEventListener('click', accedi);
        this.piede().querySelector('[data-azione="annulla"]').addEventListener('click', () => this.chiudi());
        form.username.focus();
    },

    // ---------------------------------------------------------------- dati

    async caricaEAnalizza() {
        this.messaggio('<i class="fas fa-spinner fa-spin"></i> Analisi dell\'email...');

        // L'originale .eml, letto via IMAP senza segnarlo come letto
        const userSelect = document.getElementById('userSelect');
        const account = (userSelect && userSelect.value.trim()) ? userSelect.value : currentUser;
        const r = await fetch('get_email_raw.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `user=${encodeURIComponent(account)}&file=${encodeURIComponent(currentEmailFile)}&folder=${encodeURIComponent(emailFolder)}`,
        });
        if (!r.ok) throw new Error('Impossibile leggere l\'email dalla casella');
        this.eml = await r.blob();
        const opzione = userSelect ? userSelect.options[userSelect.selectedIndex] : null;
        this.account = opzione ? (extractEmailAddress(opzione.textContent) || '') : '';
        this.direzione = ['sent', 'draft'].includes(emailFolder) ? 'uscita' : 'entrata';

        const fd = new FormData();
        fd.append('eml', this.eml, 'email.eml');
        const [analisi, tipologie, armadi, stanze] = await Promise.all([
            this.api('/email/analizza', { method: 'POST', body: fd }),
            this.api('/tipologie'),
            this.api('/armadi'),
            this.api('/stanze').catch(() => ({ data: [] })),
        ]);
        this.analisi = analisi.data;
        this.tipologie = (tipologie.data || []).filter(t => Number(t.attivo) === 1);
        this.costruisciAlbero(armadi.armadi || [], stanze.data || []);
        this.mostraModulo();
    },

    /**
     * Ufficio visibile all'utente: stanze › armadi › scaffali › (categorie) › faldoni,
     * nell'ordine dell'archivio. Gli armadi senza stanza stanno nella prima.
     */
    costruisciAlbero(armadi, stanze) {
        const perPosizione = (a, b) => (Number(a.posizione) || 0) - (Number(b.posizione) || 0) || Number(a.id) - Number(b.id);
        this.stanze = stanze.length
            ? stanze.map(s => ({ id: Number(s.id), nome: s.nome }))
            : [{ id: 0, nome: 'Ufficio' }];
        const primaStanza = this.stanze[0].id;
        this.armadi = armadi.slice().sort(perPosizione).map(a => ({
            id: Number(a.id),
            nome: a.nome,
            stanzaId: a.stanza_id ? Number(a.stanza_id) : primaStanza,
            scaffali: Object.values(a.scaffali || {}).sort(perPosizione).map(s => ({
                id: Number(s.id),
                nome: s.nome,
                categorie: Object.values(s.categorie || {}).sort(perPosizione).map(c => ({
                    id: Number(c.id),
                    nome: c.nome,
                    faldoni: (c.faldoni || []).slice().sort(perPosizione).map(f => ({
                        id: Number(f.id),
                        nome: f.nome + (f.anno ? ` (${f.anno})` : ''),
                    })),
                })),
            })),
        }));
    },

    /** Dove si trova un faldone: {stanzaId, armadio, scaffale} oppure null se non visibile */
    trovaFaldone(faldoneId) {
        for (const a of this.armadi) {
            for (const s of a.scaffali) {
                for (const c of s.categorie) {
                    const f = c.faldoni.find(x => x.id === Number(faldoneId));
                    if (f) return { stanzaId: a.stanzaId, armadio: a, scaffale: s, faldone: f };
                }
            }
        }
        return null;
    },

    async caricaCartelline(faldoneId, selezionata = null) {
        const select = this.overlay.querySelector('#archiviaCartellina');
        const vuota = '<option value="">— nel faldone, fuori dalle cartelline —</option>';
        // Vale solo l'ultima richiesta: cambiando faldone in fretta le risposte
        // precedenti arriverebbero dopo e mescolerebbero le cartelline
        const turno = this.turnoCartelline = (this.turnoCartelline || 0) + 1;
        select.innerHTML = vuota;
        if (!faldoneId) return;
        try {
            const dati = await this.api(`/faldoni/${faldoneId}/cartelline`);
            if (turno !== this.turnoCartelline || !this.overlay) return;
            select.innerHTML = vuota;
            const aggiungi = (nodi, livello) => nodi.forEach(n => {
                const o = document.createElement('option');
                o.value = n.id;
                o.textContent = '  '.repeat(livello) + (livello ? '↳ ' : '') + n.nome;
                select.appendChild(o);
                aggiungi(n.figlie || [], livello + 1);
            });
            aggiungi(dati.cartelline || [], 0);
            if (selezionata) select.value = String(selezionata);
        } catch (e) {
            if (turno === this.turnoCartelline) showToast('Cartelline non disponibili: ' + e.message, 'error');
        }
    },

    // ---------------------------------------------------------------- modulo

    opzioniTipologie(selezionata) {
        return this.tipologie.map(t =>
            `<option value="${Number(t.id)}" ${Number(t.id) === Number(selezionata) ? 'selected' : ''}>${escArchivio(t.nome)}</option>`
        ).join('');
    },

    mostraModulo() {
        const a = this.analisi;
        const tipologiaEmail = a.tipologia_suggerita_id || (this.tipologie[0] && this.tipologie[0].id);
        // Gli allegati (fatture, contratti, ...) di solito non sono "Email": si parte da "Altro"
        const altro = this.tipologie.find(t => t.nome === 'Altro');
        const tipologiaAllegati = altro ? altro.id : tipologiaEmail;

        const posizione = (p) => escArchivio(p.faldone_nome) + (p.cartellina_nome ? ' › ' + escArchivio(p.cartellina_nome) : '');

        let giaArchiviata = '';
        if (a.gia_archiviata) {
            const g = a.gia_archiviata;
            giaArchiviata = `
                <div class="archivia-avviso">
                    <i class="fas fa-info-circle"></i> Questa email è <strong>già in archivio</strong>:
                    ${escArchivio(g.titolo)} (${escArchivio(g.matricola)})${g.posizioni.length ? ', in ' + g.posizioni.map(posizione).join('; ') : ''}.
                    Puoi solo aggiungerla anche nella posizione scelta qui sotto.
                </div>`;
        }

        const ultima = this.ultimaPosizione();
        const ultimaHtml = ultima ? `
            <div class="archivia-suggerite"><span>Come l'ultima volta:</span><div>
                <button type="button" class="archivia-suggerimento" data-azione="ultima-posizione" title="L'ultima posizione in cui hai archiviato un'email">
                    <i class="fas fa-history"></i> ${escArchivio(ultima.percorso)}
                </button></div></div>` : '';

        const suggerite = (a.posizioni_suggerite || []).map((p, i) => `
            <button type="button" class="archivia-suggerimento" data-indice="${i}" title="${escArchivio(p.motivo)}">
                <i class="fas fa-folder-open"></i> ${posizione(p)}
            </button>`).join('');

        const righeAllegati = (a.allegati || []).map(al => {
            const note = [];
            if (al.inline) note.push('immagine nel testo');
            if (al.esistente) note.push(`già in archivio (${escArchivio(al.esistente.matricola)}): verrà collegato, non duplicato`);
            if (!al.archiviabile) note.push(escArchivio(al.motivo));
            return `
                <tr class="${al.archiviabile ? '' : 'archivia-escluso'}">
                    <td><input type="checkbox" data-allegato="${escArchivio(al.id)}" ${al.selezionato ? 'checked' : ''} ${al.archiviabile && !a.gia_archiviata ? '' : 'disabled'}></td>
                    <td>${escArchivio(al.nome)}<br><small>${formatFileSize(al.dimensione)}${note.length ? ' · ' + note.join(' · ') : ''}</small></td>
                    <td>${al.archiviabile && !al.esistente
                        ? `<select data-tipologia-allegato="${escArchivio(al.id)}" ${a.gia_archiviata ? 'disabled' : ''}>${this.opzioniTipologie(tipologiaAllegati)}</select>`
                        : ''}</td>
                </tr>`;
        }).join('');

        this.corpo().innerHTML = `
            <div class="archivia-intestazione">
                ${a.pec ? `<span class="archivia-pec"><i class="fas fa-certificate"></i> ${escArchivio(a.pec.descrizione)}</span>` : ''}
                <div class="archivia-oggetto">${escArchivio(a.oggetto || '(senza oggetto)')}</div>
                <div><strong>Da:</strong> ${escArchivio(a.mittente)}</div>
                <div><strong>A:</strong> ${escArchivio(a.destinatari)}</div>
                ${a.data ? `<div><strong>Data:</strong> ${escArchivio(new Date(a.data.replace(' ', 'T')).toLocaleString('it-IT'))}</div>` : ''}
            </div>
            ${giaArchiviata}
            <fieldset class="archivia-sezione archivia-dove">
                <legend><i class="fas fa-map-marker-alt"></i> Dove archiviare l'email</legend>
                ${suggerite ? `<div class="archivia-suggerite"><span>Stessa conversazione già archiviata in:</span><div>${suggerite}</div></div>` : ''}
                ${ultimaHtml}
                <div class="archivia-griglia">
                    <label ${this.stanze.length > 1 ? '' : 'hidden'}>Stanza
                        <select id="archiviaStanza"></select>
                    </label>
                    <label>Armadio
                        <select id="archiviaArmadio"></select>
                    </label>
                    <label>Scaffale
                        <select id="archiviaScaffale"></select>
                    </label>
                    <label>Faldone
                        <select id="archiviaFaldone"></select>
                    </label>
                    <div class="archivia-nuovo-faldone" id="archiviaNuovoFaldone" hidden>
                        <label>Nome del nuovo faldone
                            <input type="text" id="archiviaNuovoNome" maxlength="100" placeholder="es. Fornitori 2026">
                        </label>
                        <div class="archivia-nuovo-azioni">
                            <button type="button" class="archivia-btn-secondario" data-azione="annulla-faldone">Annulla</button>
                            <button type="button" class="archivia-btn-primario" data-azione="crea-faldone"><i class="fas fa-plus"></i> Crea faldone</button>
                        </div>
                    </div>
                    <label>Cartellina
                        <select id="archiviaCartellina"><option value="">— nel faldone, fuori dalle cartelline —</option></select>
                    </label>
                </div>
                <div class="archivia-percorso" id="archiviaPercorso"></div>
            </fieldset>
            <div class="archivia-sezione archivia-griglia">
                <label>Tipologia dell'email
                    <select id="archiviaTipologia" ${a.gia_archiviata ? 'disabled' : ''}>${this.opzioniTipologie(tipologiaEmail)}</select>
                </label>
                <label class="archivia-check">
                    <input type="checkbox" id="archiviaCreaCartellina" ${this.creaCartellinaRicordata() ? 'checked' : ''} ${a.gia_archiviata ? 'disabled' : ''}>
                    Crea una cartellina con l'oggetto (solo se archivi degli allegati)
                </label>
            </div>
            <div class="archivia-sezione">
                <label>Cosa archiviare</label>
                <table class="archivia-allegati">
                    <tr><td><input type="checkbox" checked disabled></td>
                        <td><i class="fas fa-envelope"></i> L'email (originale .eml, consultabile con allegati e firme)</td><td></td></tr>
                    ${righeAllegati}
                </table>
                ${a.pec && a.pec.tipo !== 'posta-certificata' ? '<small>Ricevuta PEC: si archivia così com\'è, verrà collegata al messaggio a cui si riferisce.</small>' : ''}
            </div>`;

        this.piede().innerHTML = `
            <button type="button" class="archivia-btn-secondario" data-azione="annulla">Annulla</button>
            ${a.gia_archiviata
                ? '<button type="button" class="archivia-btn-primario" data-azione="posizione"><i class="fas fa-folder-plus"></i> Aggiungi in questa posizione</button>'
                : '<button type="button" class="archivia-btn-primario" data-azione="archivia"><i class="fas fa-archive"></i> Archivia</button>'}`;

        this.piede().querySelector('[data-azione="annulla"]').addEventListener('click', () => this.chiudi());
        const archivia = this.piede().querySelector('[data-azione="archivia"]');
        if (archivia) archivia.addEventListener('click', () => this.archivia(archivia));
        const aggiungi = this.piede().querySelector('[data-azione="posizione"]');
        if (aggiungi) aggiungi.addEventListener('click', () => this.aggiungiPosizione(aggiungi));

        this.preparaPosizione(a.posizioni_suggerite || [], ultima);
    },

    // ---------------------------------------------------------------- ultima posizione

    /** Una per utente dell'archivio: sullo stesso PC possono lavorare più persone */
    chiaveUltimaPosizione() {
        return 'archivia.ultimaPosizione.' + (this.utente && this.utente.id ? this.utente.id : '');
    },

    /** L'ultima posizione usata, se il faldone esiste ancora ed è visibile: {faldone_id, cartellina_id, percorso} */
    ultimaPosizione() {
        let p = null;
        try { p = JSON.parse(localStorage.getItem(this.chiaveUltimaPosizione()) || 'null'); } catch (e) { /* niente */ }
        if (!p || !p.faldone_id || !this.trovaFaldone(p.faldone_id)) return null;
        return p;
    },

    /** La spunta "Crea una cartellina con l'oggetto" dell'ultima archiviazione (di partenza: sì) */
    creaCartellinaRicordata() {
        try {
            return localStorage.getItem('archivia.creaCartellina.' + (this.utente && this.utente.id ? this.utente.id : '')) !== '0';
        } catch (e) {
            return true;
        }
    },

    /** Ricorda la posizione appena usata (col percorso leggibile mostrato nella finestra) */
    ricordaPosizione(dest) {
        const percorso = this.overlay.querySelector('#archiviaPercorso').textContent.trim();
        try {
            localStorage.setItem(this.chiaveUltimaPosizione(), JSON.stringify({
                faldone_id: Number(dest.faldone_id),
                cartellina_id: dest.cartellina_id ? Number(dest.cartellina_id) : null,
                percorso,
            }));
        } catch (e) { /* localStorage non disponibile: pazienza */ }
    },

    // ---------------------------------------------------------------- posizione

    /**
     * Scelta guidata Stanza › Armadio › Scaffale › Faldone › Cartellina.
     * Il faldone è obbligatorio: senza, l'email resterebbe fuori dall'ufficio
     * (documento senza posizione) e non si ritroverebbe negli armadi.
     */
    preparaPosizione(suggerite, ultima = null) {
        const q = (id) => this.overlay.querySelector('#' + id);
        q('archiviaStanza').addEventListener('change', () => this.scegliStanza());
        q('archiviaArmadio').addEventListener('change', () => this.scegliArmadio());
        q('archiviaScaffale').addEventListener('change', () => this.scegliScaffale());
        q('archiviaFaldone').addEventListener('change', () => this.scegliFaldone());
        q('archiviaCartellina').addEventListener('change', () => this.aggiornaPercorso());
        this.corpo().querySelector('[data-azione="crea-faldone"]').addEventListener('click', (e) => this.creaFaldone(e.currentTarget));
        this.corpo().querySelector('[data-azione="annulla-faldone"]').addEventListener('click', () => {
            q('archiviaFaldone').value = '';
            this.scegliFaldone();
        });
        q('archiviaNuovoNome').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.corpo().querySelector('[data-azione="crea-faldone"]').click();
            }
        });
        this.corpo().querySelectorAll('.archivia-suggerimento').forEach(b => b.addEventListener('click', () => {
            const p = suggerite[Number(b.dataset.indice)];
            this.selezionaFaldone(p.faldone_id, p.cartellina_id);
        }));
        const bottoneUltima = this.corpo().querySelector('[data-azione="ultima-posizione"]');
        if (bottoneUltima) {
            bottoneUltima.addEventListener('click', () => this.selezionaFaldone(ultima.faldone_id, ultima.cartellina_id));
        }

        this.riempi(q('archiviaStanza'), this.stanze, '— scegli la stanza —');
        this.scegliStanza();
        // L'ultima posizione usata vince se è nello stesso faldone della conversazione
        // (è più precisa: la cartellina scelta da poco) o se non c'è una conversazione
        // con un solo posto; altrimenti la conversazione. Più email di fila nella stessa
        // cartellina senza rifare la scelta.
        const ultimaNelFaldone = ultima && suggerite.some(p => Number(p.faldone_id) === Number(ultima.faldone_id));
        if (ultima && (ultimaNelFaldone || suggerite.length !== 1)) {
            this.selezionaFaldone(ultima.faldone_id, ultima.cartellina_id);
        } else if (suggerite.length === 1) {
            this.selezionaFaldone(suggerite[0].faldone_id, suggerite[0].cartellina_id);
        }
    },

    /** Riempie una tendina; con una sola voce la sceglie subito */
    riempi(select, voci, segnaposto) {
        select.innerHTML = `<option value="">${escArchivio(voci.length ? segnaposto : '— nessuno —')}</option>`
            + voci.map(v => `<option value="${v.id}">${escArchivio(v.nome)}</option>`).join('');
        select.disabled = voci.length === 0;
        if (voci.length === 1) select.value = String(voci[0].id);
    },

    armadioScelto() {
        return this.armadi.find(a => a.id === Number(this.overlay.querySelector('#archiviaArmadio').value)) || null;
    },

    scaffaleScelto() {
        const armadio = this.armadioScelto();
        const id = Number(this.overlay.querySelector('#archiviaScaffale').value);
        return armadio ? (armadio.scaffali.find(s => s.id === id) || null) : null;
    },

    scegliStanza() {
        const stanza = Number(this.overlay.querySelector('#archiviaStanza').value);
        const armadi = this.overlay.querySelector('#archiviaStanza').value === ''
            ? [] : this.armadi.filter(a => a.stanzaId === stanza);
        this.riempi(this.overlay.querySelector('#archiviaArmadio'), armadi, '— scegli l\'armadio —');
        this.scegliArmadio();
    },

    scegliArmadio() {
        const armadio = this.armadioScelto();
        this.riempi(this.overlay.querySelector('#archiviaScaffale'), armadio ? armadio.scaffali : [], '— scegli lo scaffale —');
        this.scegliScaffale();
    },

    scegliScaffale() {
        const select = this.overlay.querySelector('#archiviaFaldone');
        const scaffale = this.scaffaleScelto();
        if (!scaffale) {
            this.riempi(select, [], '');
        } else {
            // Le categorie compaiono come gruppi solo se lo scaffale ne ha più d'una con faldoni
            const conFaldoni = scaffale.categorie.filter(c => c.faldoni.length);
            const opzioni = (fs) => fs.map(f => `<option value="${f.id}">${escArchivio(f.nome)}</option>`).join('');
            const elenco = conFaldoni.length > 1
                ? conFaldoni.map(c => `<optgroup label="${escArchivio(c.nome)}">${opzioni(c.faldoni)}</optgroup>`).join('')
                : opzioni(conFaldoni.length ? conFaldoni[0].faldoni : []);
            select.innerHTML = `<option value="">${conFaldoni.length ? '— scegli il faldone —' : '— nessun faldone: creane uno —'}</option>`
                + elenco + '<option value="nuovo">＋ Nuovo faldone in questo scaffale…</option>';
            select.disabled = false;
            const tutti = conFaldoni.flatMap(c => c.faldoni);
            if (tutti.length === 1) select.value = String(tutti[0].id);
        }
        this.scegliFaldone();
    },

    scegliFaldone() {
        const valore = this.overlay.querySelector('#archiviaFaldone').value;
        const nuovo = valore === 'nuovo';
        this.overlay.querySelector('#archiviaNuovoFaldone').hidden = !nuovo;
        if (nuovo) this.overlay.querySelector('#archiviaNuovoNome').focus();
        this.caricaCartelline(nuovo ? '' : valore).then(() => this.aggiornaPercorso());
        this.aggiornaPercorso();
    },

    /** Porta tutte le tendine sul faldone indicato (es. da un suggerimento) */
    async selezionaFaldone(faldoneId, cartellinaId = null) {
        const pos = this.trovaFaldone(faldoneId);
        if (!pos) return;
        const q = (id) => this.overlay.querySelector('#' + id);
        q('archiviaStanza').value = String(pos.stanzaId);
        this.scegliStanza();
        q('archiviaArmadio').value = String(pos.armadio.id);
        this.scegliArmadio();
        q('archiviaScaffale').value = String(pos.scaffale.id);
        this.scegliScaffale();
        q('archiviaFaldone').value = String(pos.faldone.id);
        q('archiviaNuovoFaldone').hidden = true;
        await this.caricaCartelline(pos.faldone.id, cartellinaId);
        this.aggiornaPercorso();
    },

    /** Crea un faldone nello scaffale scelto (nella sua prima categoria, creandola se manca) */
    async creaFaldone(bottone) {
        const scaffale = this.scaffaleScelto();
        const nome = this.overlay.querySelector('#archiviaNuovoNome').value.trim();
        if (!scaffale) return;
        if (!nome) {
            showToast('Scrivi il nome del nuovo faldone', 'error');
            this.overlay.querySelector('#archiviaNuovoNome').focus();
            return;
        }
        const json = (dati) => ({
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(dati),
        });
        bottone.disabled = true;
        try {
            let categoria = scaffale.categorie[0];
            if (!categoria) {
                const r = await this.api(`/scaffali/${scaffale.id}/categorie`, json({ nome: 'Categoria', colore_etichetta: '#ffffff' }));
                categoria = { id: Number(r.id), nome: 'Categoria', faldoni: [] };
                scaffale.categorie.push(categoria);
            }
            const r = await this.api(`/categorie/${categoria.id}/faldoni`, json({ nome, colore: '#3498db', livello_accesso: 'tutti' }));
            categoria.faldoni.push({ id: Number(r.id), nome });
            this.overlay.querySelector('#archiviaNuovoNome').value = '';
            await this.selezionaFaldone(r.id);
            showToast(`Faldone "${nome}" creato`, 'success');
        } catch (e) {
            showToast('Faldone non creato: ' + e.message, 'error');
        } finally {
            bottone.disabled = false;
        }
    },

    /** Riepilogo del percorso scelto e abilitazione del pulsante Archivia */
    aggiornaPercorso() {
        if (!this.overlay) return;
        const q = (id) => this.overlay.querySelector('#' + id);
        const testo = (sel) => sel.value && sel.value !== 'nuovo' ? sel.options[sel.selectedIndex].textContent.trim() : '';
        const faldone = testo(q('archiviaFaldone'));
        const percorso = q('archiviaPercorso');
        if (faldone) {
            const parti = [this.stanze.length > 1 ? testo(q('archiviaStanza')) : '', testo(q('archiviaArmadio')),
                testo(q('archiviaScaffale')), faldone, q('archiviaCartellina').value ? testo(q('archiviaCartellina')).replace(/^↳\s*/, '') : '']
                .filter(Boolean);
            percorso.className = 'archivia-percorso archivia-percorso-ok';
            percorso.innerHTML = `<i class="fas fa-check-circle"></i> ${parti.map(escArchivio).join(' › ')}`;
        } else {
            percorso.className = 'archivia-percorso';
            percorso.innerHTML = '<i class="fas fa-exclamation-circle"></i> Scegli il faldone: senza una posizione l\'email non si ritrova negli armadi.';
        }
        const pulsante = this.piede().querySelector('[data-azione="archivia"], [data-azione="posizione"]');
        if (pulsante && !pulsante.classList.contains('in-corso')) {
            pulsante.disabled = !faldone;
            pulsante.title = faldone ? '' : 'Prima scegli il faldone';
        }
    },

    // ---------------------------------------------------------------- azioni

    destinazione() {
        return {
            faldone_id: (v => v && v !== 'nuovo' ? v : null)(this.overlay.querySelector('#archiviaFaldone').value),
            cartellina_id: this.overlay.querySelector('#archiviaCartellina').value || null,
        };
    },

    async archivia(bottone) {
        const allegati = [];
        this.corpo().querySelectorAll('input[data-allegato]:checked').forEach(c => {
            const id = c.dataset.allegato;
            const tipologia = this.corpo().querySelector(`select[data-tipologia-allegato="${CSS.escape(id)}"]`);
            allegati.push({ id, tipologia_id: tipologia ? Number(tipologia.value) : undefined });
        });
        const dest = this.destinazione();
        if (!dest.faldone_id) {
            showToast('Scegli il faldone in cui archiviare l\'email', 'error');
            return;
        }
        const opzioni = {
            tipologia_id: Number(this.overlay.querySelector('#archiviaTipologia').value),
            direzione: this.direzione,
            account: this.account,
            faldone_id: dest.faldone_id,
            cartellina_id: dest.cartellina_id,
            crea_cartellina: this.overlay.querySelector('#archiviaCreaCartellina').checked,
            allegati,
        };

        bottone.disabled = true;
        bottone.classList.add('in-corso');
        bottone.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Archiviazione...';
        try {
            const fd = new FormData();
            fd.append('eml', this.eml, 'email.eml');
            fd.append('opzioni', JSON.stringify(opzioni));
            const r = (await this.api('/email/archivia', { method: 'POST', body: fd })).data;
            this.ricordaPosizione(dest);
            try {
                localStorage.setItem('archivia.creaCartellina.' + (this.utente && this.utente.id ? this.utente.id : ''),
                    opzioni.crea_cartellina ? '1' : '0');
            } catch (e) { /* localStorage non disponibile: pazienza */ }
            const allegatiTesto = r.allegati.length
                ? '<ul>' + r.allegati.map(al => `<li>${escArchivio(al.nome)} → ${escArchivio(al.matricola)}${al.riusato ? ' (già in archivio, collegato)' : ''}</li>`).join('') + '</ul>'
                : '';
            this.messaggio(`
                <div class="archivia-ok"><i class="fas fa-check-circle"></i>
                    Email archiviata con matricola <strong>${escArchivio(r.email.matricola)}</strong>${r.faldone_id ? '' : ' (senza posizione)'}.
                    ${r.cartellina_creata ? '<br>Creata la cartellina con l\'oggetto dell\'email.' : ''}
                </div>${allegatiTesto}`);
            this.piede().innerHTML = `
                <button type="button" class="archivia-btn-secondario" data-azione="chiudi">Chiudi</button>
                <button type="button" class="archivia-btn-primario" data-azione="archivio"><i class="fas fa-archive"></i> Apri l'archivio</button>`;
            this.piede().querySelector('[data-azione="chiudi"]').addEventListener('click', () => this.chiudi());
            this.piede().querySelector('[data-azione="archivio"]').addEventListener('click', () => { apriArchivio(); this.chiudi(); });
            showToast('Email archiviata: ' + r.email.matricola, 'success');
        } catch (e) {
            bottone.disabled = false;
            bottone.classList.remove('in-corso');
            bottone.innerHTML = '<i class="fas fa-archive"></i> Archivia';
            if (e.status === 401) {
                this.mostraLogin('Sessione dell\'archivio scaduta: accedi di nuovo');
                return;
            }
            showToast('Archiviazione non riuscita: ' + e.message, 'error');
        }
    },

    /** Email già archiviata: la aggiunge alla posizione scelta (API esistenti dell'archivio) */
    async aggiungiPosizione(bottone) {
        const dest = this.destinazione();
        if (!dest.faldone_id) {
            showToast('Scegli un faldone', 'error');
            return;
        }
        const id = this.analisi.gia_archiviata.id;
        bottone.disabled = true;
        try {
            const r = dest.cartellina_id
                ? await this.api(`/documenti/${id}/cartellina`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ cartellina_id: Number(dest.cartellina_id) }),
                })
                : await this.api(`/documenti/${id}/faldone`, {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ faldone_id: Number(dest.faldone_id) }),
                });
            this.ricordaPosizione(dest);
            showToast(r.message || 'Posizione aggiunta', 'success');
            this.chiudi();
        } catch (e) {
            bottone.disabled = false;
            showToast(e.message, 'error');
        }
    },
};

function apriArchiviaEmail() {
    ArchiviaEmail.apri();
}

function escArchivio(testo) {
    return String(testo ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}
