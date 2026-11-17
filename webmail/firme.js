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
 * Firme delle email (firme.php, lib/FirmeService.php), con il metodo di
 * MailCraft: ogni casella ha le sue firme, che tutti gli utenti della casella
 * vedono, usano e modificano, e ne sceglie una per le email nuove e una per
 * le risposte e gli inoltri.
 *
 * - Menu utente › Firme (o la rotellina accanto a "Firma" nel compositore):
 *   elenco delle firme della casella, modifica "Dai dati" (nome, qualifica,
 *   società, telefoni, email, sito, indirizzo, logo a sinistra o sopra,
 *   colore), "Testo formattato" o "HTML", anteprima, immagini.
 * - Compositore: la firma entra come blocco [data-firma] che non si modifica
 *   nel messaggio (si cambia o si toglie dalla scelta "Firma"); nelle
 *   risposte e negli inoltri sta sopra il messaggio citato. email_editor.js
 *   lo lascia com'è quando pulisce l'HTML; send_email.php mette le immagini
 *   dentro l'email (cid:).
 *
 * L'HTML delle firme è pulito dal server al salvataggio e di nuovo con
 * DOMPurify (pulisciHtmlEmail) prima di entrare nella pagina.
 */

const FirmeEmail = {
    URL_IMMAGINE: 'firma_immagine.php?id=',
    MAX_IMMAGINE: 256 * 1024,
    MAX_LARGHEZZA: 600,
    PESANTE: 100 * 1024,
    COLORE: '#1f4e79',
    TIPI: { dati: 'Dai dati', testo: 'Testo formattato', html: 'HTML' },
    IMPAGINAZIONI: { sinistra: 'Logo a sinistra del testo', sopra: 'Logo sopra il testo' },

    cache: {},          // account → {firme, predefinite, quando}
    giro: 0,            // inserimento automatico in corso nel compositore
    overlay: null,
    caselle: [],
    account: '',        // casella aperta nella finestra
    firme: [],
    predefinite: { nuove: null, risposte: null },
    aperta: null,       // copia della firma che si sta modificando
    salvata: '',        // stato della firma aperta all'ultimo salvataggio
    selezioneTesto: null,

    esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    pulisci(html) {
        return typeof pulisciHtmlEmail === 'function' ? pulisciHtmlEmail(html || '') : '';
    },

    kb(byte) {
        return byte < 1024 ? `${byte} byte` : `${Math.round(byte / 1024)} KB`;
    },

    async richiesta(dati, account) {
        let opzioni = { cache: 'no-store' };
        let url = 'firme.php';
        if (dati) {
            const corpo = dati instanceof FormData ? dati : new URLSearchParams(dati);
            opzioni = { method: 'POST', body: corpo };
        } else if (account) {
            url += '?account_id=' + encodeURIComponent(account);
        }
        const r = await fetch(url, opzioni);
        const data = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
        if (!data.success) throw new Error(data.error || 'Operazione non riuscita');
        return data;
    },

    /** Firme e predefinite di una casella (in memoria per un minuto). */
    async elenco(account, fresca = false) {
        account = String(account || '');
        if (!account) return { firme: [], predefinite: {} };
        const c = this.cache[account];
        if (!fresca && c && Date.now() - c.quando < 60000) return c;
        const data = await this.richiesta(null, account);
        this.cache[account] = { firme: data.firme || [], predefinite: data.predefinite || {}, quando: Date.now() };
        return this.cache[account];
    },

    dimentica(account) {
        delete this.cache[String(account)];
    },

    // ================================================================ compositore

    editor() {
        return document.getElementById('emailEditor');
    },

    accountCompositore() {
        return document.getElementById('composeAccountId')?.value || '';
    },

    compositoreAperto() {
        const m = document.getElementById('composeModal');
        return !!m && m.style.display !== 'none';
    },

    /** Il blocco della firma del messaggio (il primo: le citazioni non ne hanno). */
    bloccoMessaggio() {
        return this.editor()?.querySelector('[data-firma]') || null;
    },

    /** Firma in testo semplice, per un messaggio scritto nel riquadro di solo testo. */
    testoFirma(html) {
        const div = document.createElement('div');
        div.innerHTML = this.pulisci(html).replace(/<br\s*\/?>/gi, '\n')
            .replace(/<\/(div|p|tr|li|h\d)>/gi, '$&\n');
        return div.textContent.split('\n').map(r => r.replace(/\s+/g, ' ').trim()).filter(Boolean).join('\n');
    },

    nuovoBlocco(firma) {
        const blocco = document.createElement('div');
        blocco.setAttribute('data-firma', String(firma.id));
        // Le righe vuote prima della firma cleanHtmlForEmail le toglie: lo stacco lo dà il margine
        blocco.setAttribute('style', 'margin-top:16px;');
        this.sistemaBlocco(blocco);
        blocco.innerHTML = this.pulisci(firma.html);
        return blocco;
    },

    /** Il blocco non si modifica nel messaggio (niente title: negli inoltri partirebbe con l'email). */
    sistemaBlocco(blocco) {
        blocco.className = 'firma-blocco';
        blocco.setAttribute('contenteditable', 'false');
    },

    /**
     * Fine dell'apertura del compositore (openComposeModal, openReplyComposer):
     * mette la firma della casella, per le email nuove o per le risposte.
     * Le firme di messaggi citati (email già inviate con una firma) restano
     * testo normale della citazione.
     */
    async preparaCompositore(tipo) {
        const giro = ++this.giro;
        const ed = this.editor();
        ed?.querySelectorAll('[data-firma]').forEach(el => el.removeAttribute('data-firma'));
        const account = this.accountCompositore();
        this.riempiScelta([], null);
        if (!account) return;
        let dati;
        try {
            dati = await this.elenco(account);
        } catch (err) {
            return;   // senza firme si scrive lo stesso
        }
        if (giro !== this.giro || account !== this.accountCompositore() || !this.compositoreAperto()) return;
        const id = tipo === 'risposta' ? dati.predefinite.risposte : dati.predefinite.nuove;
        const firma = dati.firme.find(f => f.id === id && (f.html || '').trim());
        this.riempiScelta(dati.firme, firma ? firma.id : null);
        if (firma) this.metti(firma, true);
    },

    /** "Modifica bozza": la firma è già nel testo della bozza, non se ne aggiunge un'altra. */
    async bozzaAperta() {
        const giro = ++this.giro;
        const blocco = this.bloccoMessaggio();
        if (blocco) this.sistemaBlocco(blocco);
        const account = this.accountCompositore();
        let dati = { firme: [] };
        try {
            dati = await this.elenco(account);
        } catch (err) { /* resta la sola scelta "Nessuna" */ }
        if (giro !== this.giro) return;
        const id = blocco ? parseInt(blocco.getAttribute('data-firma'), 10) : null;
        this.riempiScelta(dati.firme, id);
    },

    riempiScelta(firme, scelta) {
        const sel = document.getElementById('composeFirma');
        if (!sel) return;
        sel.innerHTML = '<option value="">Nessuna</option>' + firme
            .map(f => `<option value="${f.id}">${this.esc(f.nome)}</option>`).join('');
        sel.value = scelta ? String(scelta) : '';
        if (sel.value === '' && scelta) {
            // Firma della bozza che non c'è più tra quelle della casella
            sel.insertAdjacentHTML('beforeend', `<option value="${Number(scelta)}">(firma eliminata)</option>`);
            sel.value = String(scelta);
        }
    },

    /** Scelta "Firma" del compositore cambiata a mano. */
    async sceltaCambiata(valore) {
        ++this.giro;
        const account = this.accountCompositore();
        if (!valore) {
            this.metti(null, false);
            return;
        }
        try {
            const dati = await this.elenco(account);
            const firma = dati.firme.find(f => String(f.id) === String(valore));
            if (firma) this.metti(firma, false);
        } catch (err) {
            showToast('Firma non caricata: ' + err.message, 'error');
        }
    },

    /**
     * Mette la firma nel messaggio (al posto di quella che c'è) o la toglie
     * (firma null). Nelle email nuove va in fondo, nelle risposte e negli
     * inoltri sopra il messaggio citato, dopo due righe vuote per scrivere.
     */
    metti(firma, automatica) {
        const ed = this.editor();
        const textarea = document.getElementById('composeBody');
        const bozze = typeof BozzeEmail !== 'undefined' ? BozzeEmail : null;
        // Firma messa all'apertura: non è una modifica dell'utente
        const pulita = automatica && bozze && bozze.ultimoStato && !bozze.modificata();

        if (ed && ed.style.display !== 'none') {
            const vecchio = this.bloccoMessaggio();
            if (vecchio && firma) {
                vecchio.replaceWith(this.nuovoBlocco(firma));
            } else if (vecchio) {
                vecchio.remove();
            } else if (firma) {
                const blocco = this.nuovoBlocco(firma);
                const vuoto = !ed.textContent.trim() && !ed.querySelector('img, table');
                if (vuoto) {
                    ed.innerHTML = '<div><br></div><div><br></div>';
                    ed.appendChild(blocco);
                } else {
                    // Risposta o inoltro: prima della citazione
                    const primo = ed.firstChild;
                    ed.insertBefore(blocco, primo);
                    ed.insertBefore(document.createElement('div'), blocco).innerHTML = '<br>';
                    ed.insertBefore(document.createElement('div'), blocco).innerHTML = '<br>';
                    if (primo) ed.insertBefore(document.createElement('div'), primo).innerHTML = '<br>';
                }
            }
            // Il corpo nascosto si aggiorna solo se è già in uso: finché è vuoto
            // sendEmail() prende l'HTML dell'editor così com'è (inoltri, risposte)
            if (textarea && textarea.value.trim() && typeof updateHiddenTextarea === 'function') updateHiddenTextarea();
        } else if (textarea && firma) {
            // Riquadro di solo testo (risposta a un'email senza HTML)
            const testo = this.testoFirma(firma.html);
            if (testo) textarea.value = `\n\n-- \n${testo}\n${textarea.value}`;
        }
        if (pulita) bozze.ultimoStato = bozze.stato();
    },

    // ================================================================ finestra

    costruisci() {
        if (this.overlay && document.body.contains(this.overlay)) return;
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay firme-overlay';
        overlay.innerHTML = `
            <div class="compose-modal firme-modal" role="dialog" aria-modal="true" aria-labelledby="titoloFirme">
                <div class="compose-modal-header">
                    <h3 id="titoloFirme"><i class="fas fa-signature"></i> Firme delle email</h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <div class="firme-corpo"></div>
            </div>`;
        overlay.querySelector('.btn-close-compose').addEventListener('click', () => this.chiudi());
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); this.chiudi(); } });
        document.body.appendChild(overlay);
        this.overlay = overlay;
    },

    corpo() {
        return this.overlay.querySelector('.firme-corpo');
    },

    async chiudi() {
        if (!(await this.lasciaAperta())) return;
        this.overlay?.remove();
        this.overlay = null;
        this.aperta = null;
        // Il compositore aperto sulla stessa casella vede subito le firme cambiate
        if (this.compositoreAperto()) {
            const sel = document.getElementById('composeFirma');
            const scelta = sel ? sel.value : '';
            try {
                const dati = await this.elenco(this.accountCompositore());
                this.riempiScelta(dati.firme, scelta ? parseInt(scelta, 10) : null);
            } catch (err) { /* resta com'era */ }
        }
    },

    /** Dal compositore: le firme della casella da cui si scrive. */
    apriDaCompositore() {
        this.apri(this.accountCompositore());
    },

    async apri(account) {
        document.getElementById('settingsMenu')?.classList.remove('show');
        this.costruisci();
        this.corpo().innerHTML = '<p class="firme-stato"><i class="fas fa-spinner fa-spin"></i> Caricamento...</p>';
        try {
            const data = await this.richiesta();
            this.caselle = data.caselle || [];
            if (!this.caselle.length) {
                this.corpo().innerHTML = '<p class="firme-stato">Nessuna casella disponibile.</p>';
                return;
            }
            const corrente = account || document.getElementById('userSelect')?.value || '';
            const trovata = this.caselle.find(c => String(c.id) === String(corrente));
            await this.apriCasella(String((trovata || this.caselle[0]).id));
        } catch (err) {
            this.corpo().innerHTML = `<p class="firme-stato errore">${this.esc(err.message)}</p>`;
        }
    },

    casella() {
        return this.caselle.find(c => String(c.id) === String(this.account)) || {};
    },

    async apriCasella(account, idDaAprire = null) {
        const dati = await this.elenco(account, true);
        this.account = String(account);
        this.firme = dati.firme;
        this.predefinite = dati.predefinite;
        this.mostra();
        const prima = this.firme.find(f => f.id === idDaAprire) || this.firme[0];
        if (prima) this.apriFirma(prima.id, true);
    },

    mostra() {
        const opzioniCaselle = this.caselle.map(c =>
            `<option value="${c.id}"${String(c.id) === this.account ? ' selected' : ''}>${this.esc(c.nome || c.email)}${c.nome && c.email ? ' — ' + this.esc(c.email) : ''}</option>`).join('');
        const opzioniFirme = (scelta) => '<option value="">Nessuna</option>' + this.firme.map(f =>
            `<option value="${f.id}"${f.id === scelta ? ' selected' : ''}>${this.esc(f.nome)}</option>`).join('');
        this.corpo().innerHTML = `
            <p class="firme-nota">Le firme sono della casella: le vedono, le usano e le modificano tutti quelli che la usano.</p>
            <label class="firme-casella">Casella <select data-campo="casella">${opzioniCaselle}</select></label>
            <div class="firme-colonne">
                <div class="firme-sinistra">
                    <ul class="firme-elenco">${this.firme.map(f => this.voceElenco(f)).join('')
                        || '<li class="firme-vuoto">Nessuna firma per questa casella.</li>'}</ul>
                    <button type="button" class="archivia-btn-primario" data-azione="nuova"><i class="fas fa-plus"></i> Nuova firma</button>
                </div>
                <div class="firme-destra">
                    ${this.firme.length ? '' : '<p class="firme-stato">Crea la prima firma con «Nuova firma».</p>'}
                </div>
            </div>
            <fieldset class="firme-predefinite">
                <legend>Firma che entra da sola nei messaggi di questa casella</legend>
                <div class="firme-due">
                    <label>Email nuove <select data-campo="nuove">${opzioniFirme(this.predefinite.nuove)}</select></label>
                    <label>Risposte e inoltri <select data-campo="risposte">${opzioniFirme(this.predefinite.risposte)}</select></label>
                </div>
            </fieldset>`;
        const c = this.corpo();
        c.querySelector('[data-campo="casella"]').addEventListener('change', async (e) => {
            const scelta = e.target.value;
            if (!(await this.lasciaAperta())) {
                e.target.value = this.account;
                return;
            }
            this.aperta = null;
            this.apriCasella(scelta).catch(err => showToast(err.message, 'error'));
        });
        c.querySelector('[data-azione="nuova"]').addEventListener('click', () => this.nuova());
        c.querySelectorAll('.firme-elenco [data-id]').forEach(li => li.addEventListener('click', async () => {
            const id = parseInt(li.dataset.id, 10);
            if (this.aperta && this.aperta.id === id) return;
            if (await this.lasciaAperta()) this.apriFirma(id);
        }));
        c.querySelectorAll('.firme-predefinite select').forEach(s => s.addEventListener('change', () => this.salvaPredefinite()));
    },

    voceElenco(f) {
        const segni = [];
        if (f.id === this.predefinite.nuove) segni.push('email nuove');
        if (f.id === this.predefinite.risposte) segni.push('risposte');
        return `<li data-id="${f.id}" class="${this.aperta && this.aperta.id === f.id ? 'attiva' : ''}" tabindex="0">
            <strong>${this.esc(f.nome)}</strong>
            <small>${this.esc(this.TIPI[f.tipo] || '')}${segni.length ? ' · ' + segni.join(', ') : ''}</small></li>`;
    },

    /** Ridisegna elenco e predefinite, tenendo aperta la firma che si sta modificando. */
    mostraElencoE() {
        const destra = this.corpo().querySelector('.firme-destra');
        const modulo = destra ? Array.from(destra.childNodes) : [];
        this.mostra();
        const nuova = this.corpo().querySelector('.firme-destra');
        nuova.innerHTML = '';
        modulo.forEach(n => nuova.appendChild(n));
    },

    async salvaPredefinite() {
        const c = this.corpo();
        const nuove = c.querySelector('[data-campo="nuove"]').value;
        const risposte = c.querySelector('[data-campo="risposte"]').value;
        try {
            await this.richiesta({ azione: 'predefinite', account_id: this.account, nuove, risposte });
            this.predefinite = { nuove: nuove ? parseInt(nuove, 10) : null, risposte: risposte ? parseInt(risposte, 10) : null };
            this.dimentica(this.account);
            this.mostraElencoE();
            showToast('Firme della casella salvate', 'success');
        } catch (err) {
            showToast(err.message, 'error');
        }
    },

    async nuova() {
        if (!(await this.lasciaAperta())) return;
        const nome = prompt('Nome della nuova firma (per esempio "Completa" o "Breve"):', this.firme.length ? '' : 'Completa');
        if (nome === null || !nome.trim()) return;
        try {
            const data = await this.richiesta({ azione: 'crea', account_id: this.account, nome: nome.trim() });
            this.dimentica(this.account);
            this.aperta = null;
            await this.apriCasella(this.account, data.id);
            showToast('Firma creata: compila i dati e salva', 'success');
        } catch (err) {
            showToast(err.message, 'error');
        }
    },

    // ---------------------------------------------------------------- una firma

    apriFirma(id) {
        const f = this.firme.find(x => x.id === id);
        if (!f) return;
        const casella = this.casella();
        const dati = Object.assign({
            nome: '', qualifica: '', societa: casella.societa || '', telefono: '', cellulare: '',
            email: casella.email || '', sito: '', indirizzo: '', impaginazione: 'sinistra', colore: this.COLORE,
            logo: '', logo_larghezza: '', separatore: '',
        }, f.dati || {});
        this.aperta = {
            id: f.id, nome: f.nome, tipo: f.tipo || 'dati', html: f.html || '', dati,
            immagini: (f.immagini || []).slice(),
        };
        if (this.aperta.tipo === 'dati' && !this.aperta.html) this.aperta.html = this.genera(dati);
        this.corpo().querySelectorAll('.firme-elenco li').forEach(li =>
            li.classList.toggle('attiva', parseInt(li.dataset.id, 10) === id));
        this.disegnaFirma();
        this.salvata = this.statoAperta();
    },

    statoAperta() {
        if (!this.aperta) return '';
        this.leggiModulo();
        const a = this.aperta;
        return JSON.stringify([a.nome, a.tipo, a.html, a.tipo === 'dati' ? a.dati : null]);
    },

    /** Prima di lasciare la firma aperta: con modifiche non salvate chiede. */
    async lasciaAperta() {
        if (!this.aperta || !this.overlay) return true;
        if (this.statoAperta() === this.salvata) return true;
        if (!confirm(`La firma «${this.aperta.nome}» ha modifiche non salvate. Salvarle?\n\nOK = salva\nAnnulla = lascia perdere le modifiche`)) {
            return true;
        }
        return this.salva();
    },

    disegnaFirma() {
        const a = this.aperta;
        const destra = this.corpo().querySelector('.firme-destra');
        const schede = Object.entries(this.TIPI).map(([k, v]) =>
            `<button type="button" role="tab" data-tipo="${k}" class="${a.tipo === k ? 'attiva' : ''}" aria-selected="${a.tipo === k}">${v}</button>`).join('');
        destra.innerHTML = `
            <form class="firme-form" autocomplete="off">
                <label class="firme-nome">Nome della firma <input type="text" data-campo="nome" maxlength="100" value="${this.esc(a.nome)}"></label>
                <div class="firme-schede" role="tablist">${schede}</div>
                <div class="firme-scheda"></div>
                <div class="firme-anteprima-titolo">
                    <span>Anteprima: come la vede chi riceve l'email</span>
                    <span class="firme-peso"></span>
                </div>
                <iframe class="firme-anteprima" sandbox="allow-same-origin" referrerpolicy="no-referrer" title="Anteprima della firma"></iframe>
                <p class="firme-errore" hidden></p>
                <div class="firme-azioni">
                    <button type="button" class="archivia-btn-secondario firme-elimina" data-azione="elimina"><i class="fas fa-trash"></i> Elimina firma</button>
                    <span class="firme-spazio"></span>
                    <button type="submit" class="archivia-btn-primario"><i class="fas fa-check"></i> Salva</button>
                </div>
            </form>`;
        const form = destra.querySelector('form');
        form.addEventListener('submit', (e) => { e.preventDefault(); this.salva(); });
        form.addEventListener('input', () => this.anteprima());
        form.addEventListener('change', () => this.anteprima());
        form.querySelector('[data-azione="elimina"]').addEventListener('click', () => this.elimina());
        form.querySelectorAll('.firme-schede button').forEach(b => b.addEventListener('click', () => this.cambiaTipo(b.dataset.tipo)));
        this.disegnaScheda();
    },

    disegnaScheda() {
        const a = this.aperta;
        const scheda = this.corpo().querySelector('.firme-scheda');
        if (a.tipo === 'dati') scheda.innerHTML = this.schedaDati();
        else if (a.tipo === 'testo') scheda.innerHTML = this.schedaTesto();
        else scheda.innerHTML = this.schedaHtml();
        this.collegaScheda(scheda);
        this.anteprima();
    },

    schedaDati() {
        const d = this.aperta.dati;
        const campo = (k, etichetta, tipo = 'text', segnaposto = '') =>
            `<label>${etichetta} <input type="${tipo}" data-dato="${k}" maxlength="200" value="${this.esc(d[k])}" placeholder="${this.esc(segnaposto)}"></label>`;
        const logo = this.aperta.immagini.find(i => String(i.id) === String(d.logo));
        const impaginazioni = Object.entries(this.IMPAGINAZIONI).map(([k, v]) =>
            `<option value="${k}"${d.impaginazione === k ? ' selected' : ''}>${v}</option>`).join('');
        return `
            <p class="firme-nota piccola">Compila solo i campi che ti servono: la firma si compone da sola, con telefono, email e sito cliccabili.</p>
            <div class="firme-due">
                ${campo('nome', 'Nome e cognome', 'text', 'Mario Rossi')}
                ${campo('qualifica', 'Qualifica', 'text', 'Responsabile tecnico')}
                ${campo('societa', 'Società', 'text', 'Rossi S.r.l.')}
                ${campo('sito', 'Sito', 'text', 'www.esempio.it')}
                ${campo('telefono', 'Telefono', 'tel', '095 123456')}
                ${campo('cellulare', 'Cellulare', 'tel', '333 1234567')}
                ${campo('email', 'Email', 'email', 'nome@esempio.it')}
                <label>Colore <input type="color" data-dato="colore" value="${this.esc(/^#[0-9a-f]{6}$/i.test(d.colore) ? d.colore : this.COLORE)}"></label>
            </div>
            <label class="firme-riga">Indirizzo <textarea data-dato="indirizzo" rows="2" maxlength="200" placeholder="Via Roma 1&#10;95100 Catania">${this.esc(d.indirizzo)}</textarea></label>
            <fieldset class="firme-logo">
                <legend>Logo</legend>
                ${logo ? `<div class="firme-logo-scelto">
                        <img src="${this.URL_IMMAGINE}${logo.id}" alt="">
                        <span>${this.esc(logo.nome)}<small>${logo.larghezza}×${logo.altezza} px · ${this.kb(logo.byte)}</small></span>
                        <button type="button" class="filtri-togli" data-azione="togli-logo" title="Togli il logo"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="firme-due">
                        <label>Posizione <select data-dato="impaginazione">${impaginazioni}</select></label>
                        <label>Larghezza (pixel) <input type="number" data-dato="logo_larghezza" min="16" max="${this.MAX_LARGHEZZA}" value="${this.esc(d.logo_larghezza || Math.min(logo.larghezza, 150))}"></label>
                    </div>`
                : `<button type="button" class="archivia-btn-secondario" data-azione="logo"><i class="fas fa-image"></i> Scegli il logo…</button>
                   <small class="firme-nota piccola">PNG, JPEG o GIF; se è grande la webmail lo rimpicciolisce.</small>`}
            </fieldset>
            <label class="firme-scelta"><input type="checkbox" data-dato="separatore"${d.separatore ? ' checked' : ''}> Riga «--» prima della firma (la usano molti programmi di posta per riconoscerla)</label>`;
    },

    schedaTesto() {
        return `
            <p class="firme-nota piccola">Scrivi la firma come vuoi che appaia. Per il logo usa «Immagine» o le immagini qui sotto.</p>
            <div class="firme-barra">
                <button type="button" data-comando="bold" title="Grassetto"><i class="fas fa-bold"></i></button>
                <button type="button" data-comando="italic" title="Corsivo"><i class="fas fa-italic"></i></button>
                <button type="button" data-comando="underline" title="Sottolineato"><i class="fas fa-underline"></i></button>
                <label class="firme-colore-testo" title="Colore del testo"><i class="fas fa-palette"></i><input type="color" data-comando="foreColor" value="${this.COLORE}"></label>
                <select data-comando="fontSize" title="Dimensione">
                    <option value="">Dimensione</option><option value="1">Piccola</option><option value="2">Normale</option>
                    <option value="3">Media</option><option value="4">Grande</option><option value="5">Molto grande</option>
                </select>
                <button type="button" data-comando="link" title="Link"><i class="fas fa-link"></i></button>
                <button type="button" data-azione="immagine" title="Inserisci un'immagine"><i class="fas fa-image"></i></button>
                <button type="button" data-comando="removeFormat" title="Togli la formattazione"><i class="fas fa-eraser"></i></button>
            </div>
            <div class="firme-editor" contenteditable="true" spellcheck="true">${this.pulisci(this.aperta.html)}</div>
            ${this.elencoImmagini()}`;
    },

    schedaHtml() {
        return `
            <p class="firme-nota piccola">Scrivi o incolla l'HTML della firma (per esempio fatto con un generatore di firme).
                Al salvataggio si tolgono script, moduli, stili con url() e link non sicuri; le immagini possono essere
                indirizzi https:// o quelle della firma qui sotto.</p>
            <textarea class="firme-sorgente" spellcheck="false" rows="12">${this.esc(this.aperta.html)}</textarea>
            ${this.elencoImmagini()}`;
    },

    elencoImmagini() {
        const imm = this.aperta.immagini;
        const totale = imm.reduce((s, i) => s + i.byte, 0);
        return `
            <fieldset class="firme-immagini">
                <legend>Immagini della firma</legend>
                ${imm.length ? `<ul>${imm.map(i => `
                    <li>
                        <img src="${this.URL_IMMAGINE}${i.id}" alt="">
                        <span>${this.esc(i.nome)}<small>${i.larghezza}×${i.altezza} px · ${this.kb(i.byte)}</small></span>
                        <button type="button" class="archivia-btn-secondario" data-inserisci="${i.id}">Inserisci</button>
                        <button type="button" class="filtri-togli" data-elimina-immagine="${i.id}" title="Elimina l'immagine"><i class="fas fa-trash"></i></button>
                    </li>`).join('')}</ul>` : ''}
                <button type="button" class="filtri-link" data-azione="immagine"><i class="fas fa-plus"></i> Aggiungi un'immagine</button>
                <small class="firme-nota piccola">Al massimo 10 immagini e 512 KB in tutto${totale ? ` (ora ${this.kb(totale)})` : ''}.
                    Le immagini non usate nella firma si cancellano al salvataggio.</small>
            </fieldset>`;
    },

    collegaScheda(scheda) {
        scheda.querySelectorAll('[data-azione="logo"], [data-azione="immagine"]').forEach(b =>
            b.addEventListener('click', () => this.scegliImmagine(b.dataset.azione === 'logo')));
        scheda.querySelector('[data-azione="togli-logo"]')?.addEventListener('click', () => {
            this.leggiModulo();
            this.aperta.dati.logo = '';
            this.disegnaScheda();
        });
        scheda.querySelectorAll('[data-inserisci]').forEach(b =>
            b.addEventListener('click', () => this.inserisciImmagine(parseInt(b.dataset.inserisci, 10))));
        scheda.querySelectorAll('[data-elimina-immagine]').forEach(b =>
            b.addEventListener('click', () => this.eliminaImmagine(parseInt(b.dataset.eliminaImmagine, 10))));

        const ed = scheda.querySelector('.firme-editor');
        if (ed) {
            const ricorda = () => {
                const sel = window.getSelection();
                if (sel.rangeCount && ed.contains(sel.anchorNode)) this.selezioneTesto = sel.getRangeAt(0).cloneRange();
            };
            ed.addEventListener('keyup', ricorda);
            ed.addEventListener('mouseup', ricorda);
            ed.addEventListener('input', ricorda);
            scheda.querySelectorAll('.firme-barra [data-comando]').forEach(el => {
                const evento = el.tagName === 'BUTTON' ? 'click' : 'change';
                el.addEventListener(evento, () => this.comando(el.dataset.comando, el.value));
                if (el.tagName === 'BUTTON') el.addEventListener('mousedown', e => e.preventDefault());
            });
        }
    },

    ripristinaSelezione() {
        const ed = this.corpo().querySelector('.firme-editor');
        if (!ed) return null;
        ed.focus();
        if (this.selezioneTesto && ed.contains(this.selezioneTesto.startContainer)) {
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(this.selezioneTesto);
        }
        return ed;
    },

    comando(nome, valore) {
        const ed = this.ripristinaSelezione();
        if (!ed) return;
        if (nome === 'link') {
            const url = prompt('Indirizzo del link (https://…, mailto:… o tel:…):', 'https://');
            if (!url || !/^(https?:\/\/|mailto:|tel:)/i.test(url.trim())) return;
            document.execCommand('createLink', false, url.trim());
        } else if (nome === 'fontSize' || nome === 'foreColor') {
            if (!valore) return;
            document.execCommand('styleWithCSS', false, true);
            document.execCommand(nome, false, valore);
            const s = this.corpo().querySelector('.firme-barra select');
            if (nome === 'fontSize' && s) s.value = '';
        } else {
            document.execCommand(nome, false, null);
        }
        this.anteprima();
    },

    tagImmagine(img) {
        const larghezza = Math.min(img.larghezza, this.MAX_LARGHEZZA);
        return `<img src="${this.URL_IMMAGINE}${img.id}" width="${larghezza}" alt="${this.esc(img.nome.replace(/\.[a-z0-9]+$/i, ''))}" style="border:0;">`;
    },

    inserisciImmagine(id) {
        const img = this.aperta.immagini.find(i => i.id === id);
        if (!img) return;
        if (this.aperta.tipo === 'testo') {
            if (!this.ripristinaSelezione()) return;
            document.execCommand('insertHTML', false, this.tagImmagine(img));
        } else if (this.aperta.tipo === 'html') {
            const ta = this.corpo().querySelector('.firme-sorgente');
            const pos = ta.selectionStart ?? ta.value.length;
            ta.value = ta.value.slice(0, pos) + this.tagImmagine(img) + ta.value.slice(ta.selectionEnd ?? pos);
        }
        this.anteprima();
    },

    /** Legge nel modello quello che c'è nel modulo. */
    leggiModulo() {
        const a = this.aperta;
        if (!a || !this.overlay) return;
        const form = this.corpo().querySelector('.firme-form');
        if (!form) return;
        a.nome = (form.querySelector('[data-campo="nome"]')?.value || '').trim();
        if (a.tipo === 'dati') {
            form.querySelectorAll('[data-dato]').forEach(el => {
                a.dati[el.dataset.dato] = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
            });
            a.html = this.genera(a.dati);
        } else if (a.tipo === 'testo') {
            const ed = form.querySelector('.firme-editor');
            if (ed) a.html = ed.innerHTML.trim();
        } else {
            const ta = form.querySelector('.firme-sorgente');
            if (ta) a.html = ta.value.trim();
        }
    },

    cambiaTipo(tipo) {
        const a = this.aperta;
        if (!a || tipo === a.tipo) return;
        this.leggiModulo();
        if (tipo === 'dati' && a.html && a.html !== this.genera(a.dati)
                && !confirm('La firma sarà ricomposta dai dati: le modifiche fatte a mano si perdono. Continuare?')) {
            return;
        }
        a.tipo = tipo;
        if (tipo === 'dati') a.html = this.genera(a.dati);
        this.corpo().querySelectorAll('.firme-schede button').forEach(b => {
            b.classList.toggle('attiva', b.dataset.tipo === tipo);
            b.setAttribute('aria-selected', String(b.dataset.tipo === tipo));
        });
        this.disegnaScheda();
    },

    anteprima() {
        if (!this.aperta || !this.overlay) return;
        this.leggiModulo();
        const frame = this.corpo().querySelector('.firme-anteprima');
        if (!frame) return;
        const html = this.pulisci(this.aperta.html);
        frame.srcdoc = `<!DOCTYPE html><html><head><meta charset="utf-8"><base target="_blank">
            <style>body{margin:12px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#333;background:#fff}</style>
            </head><body>${html || '<p style="color:#999">(firma vuota)</p>'}</body></html>`;
        const usate = new Set((this.aperta.html.match(/firma_immagine\.php\?id=(\d+)/g) || []).map(s => s.replace(/\D/g, '')));
        const peso = this.aperta.immagini.filter(i => usate.has(String(i.id))).reduce((s, i) => s + i.byte, 0);
        const el = this.corpo().querySelector('.firme-peso');
        if (el) {
            el.textContent = peso ? `Immagini: ${this.kb(peso)} in ogni email` : '';
            el.classList.toggle('pesante', peso > this.PESANTE);
            el.title = peso > this.PESANTE ? 'Oltre 100 KB ogni email diventa sensibilmente più pesante: meglio un logo più piccolo' : '';
        }
    },

    // ---------------------------------------------------------------- firma dai dati

    /**
     * L'HTML della firma composta dai dati: una tabella con stili in linea
     * (la leggono bene tutti i programmi di posta), il nome in grassetto e nel
     * colore scelto, telefono, email e sito come link, il logo a sinistra del
     * testo (separato da una riga verticale) o sopra.
     */
    genera(d) {
        const e = v => this.esc(String(v ?? '').trim());
        const colore = /^#[0-9a-f]{6}$/i.test(d.colore || '') ? d.colore : this.COLORE;
        const righe = [];
        if ((d.nome || '').trim()) righe.push(`<div style="font-size:15px;font-weight:bold;color:${colore};">${e(d.nome)}</div>`);
        if ((d.qualifica || '').trim()) righe.push(`<div style="color:#555555;">${e(d.qualifica)}</div>`);
        if ((d.societa || '').trim()) righe.push(`<div style="font-weight:bold;">${e(d.societa)}</div>`);
        const contatti = [];
        const tel = (n) => {
            const piu = n.trim().startsWith('+');
            const cifre = n.replace(/\(0\)/g, piu ? '' : '(0)').replace(/\D/g, '');
            return cifre.length >= 3 ? `tel:${piu ? '+' : ''}${cifre}` : '';
        };
        for (const [k, etichetta] of [['telefono', 'Tel.'], ['cellulare', 'Cell.']]) {
            const n = (d[k] || '').trim();
            if (!n) continue;
            const link = tel(n);
            contatti.push(`<div>${etichetta} ${link ? `<a href="${link}" style="color:#333333;text-decoration:none;">${e(n)}</a>` : e(n)}</div>`);
        }
        const email = (d.email || '').trim();
        if (email) {
            contatti.push(/^[^\s@]+@[^\s@]+$/.test(email)
                ? `<div><a href="mailto:${e(email)}" style="color:${colore};text-decoration:none;">${e(email)}</a></div>`
                : `<div>${e(email)}</div>`);
        }
        const sito = (d.sito || '').trim();
        if (sito) {
            const url = /^https?:\/\//i.test(sito) ? sito : 'https://' + sito;
            const mostrato = sito.replace(/^https?:\/\//i, '').replace(/\/$/, '');
            contatti.push(/^https?:\/\/[^\s"'<>]+$/i.test(url)
                ? `<div><a href="${e(url)}" style="color:${colore};text-decoration:none;">${e(mostrato)}</a></div>`
                : `<div>${e(sito)}</div>`);
        }
        if (contatti.length) righe.push(`<div style="margin-top:4px;">${contatti.join('')}</div>`);
        const indirizzo = String(d.indirizzo || '').split('\n').map(r => r.trim()).filter(Boolean);
        if (indirizzo.length) {
            righe.push(`<div style="margin-top:4px;color:#777777;">${indirizzo.map(r => e(r)).join('<br>')}</div>`);
        }

        const logo = this.aperta?.immagini.find(i => String(i.id) === String(d.logo));
        if (!righe.length && !logo) return '';
        const testo = righe.join('');
        const tabella = 'cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.45;color:#333333;"';
        let corpo;
        if (logo) {
            let larghezza = parseInt(d.logo_larghezza, 10) || Math.min(logo.larghezza, 150);
            larghezza = Math.max(16, Math.min(larghezza, this.MAX_LARGHEZZA));
            const img = `<img src="${this.URL_IMMAGINE}${logo.id}" width="${larghezza}" alt="${e(d.societa || d.nome || 'Logo')}" style="display:block;border:0;">`;
            if (!righe.length) {
                corpo = `<tr><td style="padding:0;">${img}</td></tr>`;
            } else if (d.impaginazione === 'sopra') {
                corpo = `<tr><td style="padding:0 0 8px 0;">${img}</td></tr><tr><td style="padding:0;">${testo}</td></tr>`;
            } else {
                corpo = `<tr><td style="padding:0 14px 0 0;vertical-align:top;">${img}</td>`
                    + `<td style="padding:0 0 0 14px;vertical-align:top;border-left:2px solid ${colore};">${testo}</td></tr>`;
            }
        } else {
            corpo = `<tr><td style="padding:0;">${testo}</td></tr>`;
        }
        const separatore = d.separatore ? '<div style="color:#888888;">--&nbsp;</div>' : '';
        return `${separatore}<table ${tabella}>${corpo}</table>`;
    },

    // ---------------------------------------------------------------- immagini

    scegliImmagine(comeLogo) {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/png,image/jpeg,image/gif';
        input.addEventListener('change', () => {
            if (input.files[0]) this.caricaImmagine(input.files[0], comeLogo);
        });
        input.click();
    },

    async caricaImmagine(file, comeLogo) {
        const errore = this.corpo().querySelector('.firme-errore');
        if (errore) errore.hidden = true;
        try {
            if (!/^image\/(png|jpeg|gif)$/.test(file.type)) throw new Error('Sono ammesse solo immagini PNG, JPEG o GIF');
            const { blob, nome, rimpicciolita } = await this.preparaImmagine(file);
            const dati = new FormData();
            dati.append('azione', 'immagine');
            dati.append('id', String(this.aperta.id));
            dati.append('immagine', blob, nome);
            const data = await this.richiesta(dati);
            this.leggiModulo();
            this.aperta.immagini.push(data.immagine);
            this.dimentica(this.account);
            if (comeLogo) {
                this.aperta.dati.logo = String(data.immagine.id);
                this.aperta.dati.logo_larghezza = String(Math.min(data.immagine.larghezza, 150));
            }
            this.disegnaScheda();
            if (!comeLogo) this.inserisciImmagine(data.immagine.id);
            if (rimpicciolita) {
                showToast(`L'immagine era grande ed è stata rimpicciolita (${data.immagine.larghezza} pixel di larghezza)`, 'info');
            }
        } catch (err) {
            this.mostraErrore(err.message);
        }
    },

    /**
     * L'immagine come la si manda al server: com'è se pesa al massimo 256 KB
     * ed è larga al massimo 600 pixel, altrimenti ridisegnata più piccola
     * (PNG se ha trasparenze, altrimenti JPEG), come fa MailCraft.
     */
    async preparaImmagine(file) {
        const url = URL.createObjectURL(file);
        try {
            const img = await new Promise((ok, ko) => {
                const i = new Image();
                i.onload = () => ok(i);
                i.onerror = () => ko(new Error('Immagine non leggibile'));
                i.src = url;
            });
            if (file.size <= this.MAX_IMMAGINE && img.naturalWidth <= this.MAX_LARGHEZZA) {
                return { blob: file, nome: file.name, rimpicciolita: false };
            }
            const base = file.name.replace(/\.[a-z0-9]+$/i, '') || 'immagine';
            let larghezza = Math.min(img.naturalWidth, this.MAX_LARGHEZZA);
            for (let tentativo = 0; tentativo < 5 && larghezza >= 16; tentativo++) {
                const altezza = Math.max(1, Math.round(img.naturalHeight * larghezza / img.naturalWidth));
                const canvas = document.createElement('canvas');
                canvas.width = larghezza;
                canvas.height = altezza;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, larghezza, altezza);
                const trasparente = file.type !== 'image/jpeg' && this.haTrasparenza(ctx, larghezza, altezza);
                let blob = await new Promise(ok => canvas.toBlob(ok, 'image/png'));
                let est = 'png';
                if (blob && blob.size > this.MAX_IMMAGINE && !trasparente) {
                    blob = await new Promise(ok => canvas.toBlob(ok, 'image/jpeg', 0.85));
                    est = 'jpg';
                }
                if (blob && blob.size <= this.MAX_IMMAGINE) return { blob, nome: `${base}.${est}`, rimpicciolita: true };
                larghezza = Math.floor(larghezza / 2);
            }
            throw new Error("L'immagine è troppo grande anche rimpicciolita: usane una più leggera");
        } finally {
            URL.revokeObjectURL(url);
        }
    },

    haTrasparenza(ctx, w, h) {
        const dati = ctx.getImageData(0, 0, w, h).data;
        for (let i = 3; i < dati.length; i += 4) {
            if (dati[i] < 255) return true;
        }
        return false;
    },

    async eliminaImmagine(id) {
        if (!confirm("Eliminare questa immagine? Se è nella firma, sparisce anche da lì.")) return;
        try {
            await this.richiesta({ azione: 'elimina_immagine', id });
            this.leggiModulo();
            const a = this.aperta;
            a.immagini = a.immagini.filter(i => i.id !== id);
            if (String(a.dati.logo) === String(id)) a.dati.logo = '';
            if (a.tipo !== 'dati') {
                // Toglie l'immagine anche dal testo della firma
                const div = document.createElement('div');
                div.innerHTML = this.pulisci(a.html);
                div.querySelectorAll(`img[src="${this.URL_IMMAGINE}${id}"]`).forEach(i => i.remove());
                a.html = div.innerHTML;
                if (a.tipo === 'testo') this.corpo().querySelector('.firme-editor').innerHTML = a.html;
                else this.corpo().querySelector('.firme-sorgente').value = a.html;
            }
            this.dimentica(this.account);
            this.disegnaScheda();
        } catch (err) {
            this.mostraErrore(err.message);
        }
    },

    mostraErrore(testo) {
        const el = this.corpo().querySelector('.firme-errore');
        if (el) {
            el.textContent = testo;
            el.hidden = false;
        } else {
            showToast(testo, 'error');
        }
    },

    // ---------------------------------------------------------------- salva, elimina

    /** @return Promise<bool> */
    async salva() {
        const a = this.aperta;
        if (!a) return true;
        this.leggiModulo();
        const errore = this.corpo().querySelector('.firme-errore');
        if (errore) errore.hidden = true;
        if (!a.nome) {
            this.mostraErrore('Scrivi il nome della firma');
            return false;
        }
        try {
            const data = await this.richiesta({
                azione: 'salva', id: a.id, nome: a.nome, tipo: a.tipo, html: a.html,
                dati: JSON.stringify(a.dati),
            });
            this.dimentica(this.account);
            const pulito = data.html ?? a.html;
            // Il server può aver tolto qualcosa (HTML non ammesso, immagini non usate)
            const usate = new Set((pulito.match(/firma_immagine\.php\?id=(\d+)/g) || []).map(s => parseInt(s.replace(/\D/g, ''), 10)));
            const immagini = a.immagini.filter(i => usate.has(i.id));
            const tolte = immagini.length !== a.immagini.length;
            const cambiata = a.tipo === 'html' && pulito !== a.html;
            a.immagini = immagini;
            a.html = pulito;
            if (cambiata || tolte) this.disegnaScheda();
            if (cambiata) showToast('Firma salvata: tolte le parti di HTML non ammesse', 'info');
            else showToast('Firma salvata', 'success');
            const f = this.firme.find(x => x.id === a.id);
            if (f) Object.assign(f, { nome: a.nome, tipo: a.tipo, html: pulito, dati: { ...a.dati }, immagini: a.immagini.slice() });
            this.salvata = this.statoAperta();
            this.mostraElencoE();
            this.corpo().querySelectorAll('.firme-elenco li').forEach(li =>
                li.classList.toggle('attiva', parseInt(li.dataset.id, 10) === a.id));
            return true;
        } catch (err) {
            this.mostraErrore(err.message);
            return false;
        }
    },

    async elimina() {
        const a = this.aperta;
        if (!a || !confirm(`Eliminare la firma «${a.nome}»? Non entrerà più nei messaggi di questa casella.`)) return;
        try {
            await this.richiesta({ azione: 'elimina', id: a.id });
            this.dimentica(this.account);
            this.aperta = null;
            await this.apriCasella(this.account);
            showToast('Firma eliminata', 'success');
        } catch (err) {
            showToast(err.message, 'error');
        }
    },
};

// Firme della casella scelta già in memoria quando si apre il compositore
document.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'userSelect' && e.target.value) FirmeEmail.elenco(e.target.value).catch(() => {});
});
