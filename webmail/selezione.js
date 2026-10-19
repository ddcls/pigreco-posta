/**
 * Selezione multipla nell'elenco delle email, "Sposta in…" ed email importanti.
 *
 * - Ogni riga dell'elenco (#emailList, anche i risultati della ricerca) riceve
 *   una casella di spunta e la stella ⭐, aggiunte qui quando l'elenco viene
 *   ridisegnato. La stella piena dipende da data-importante sulla riga.
 * - Spunta = seleziona senza aprire; Maiusc+clic seleziona un intervallo;
 *   Ctrl/Cmd+clic su una riga la seleziona; Esc toglie la selezione.
 * - La barra sopra l'elenco (#barraSelezione) ha "seleziona tutte" e, con
 *   email selezionate: Sposta in…, Cestino (dal Cestino: Ripristina ed
 *   Elimina definitivamente), ⭐ Importante, Letta, Non letta.
 * - "Sposta in…" e ⭐ del dettaglio agiscono sull'email aperta.
 * - Trascinando una email (o una delle selezionate: tutte quelle selezionate)
 *   su una cartella del pannello a sinistra la si sposta lì.
 *
 * Un'email è identificata da cartella + UID (data-folder, data-file): nei
 * risultati della ricerca in tutte le cartelle due email possono avere lo
 * stesso UID. Le azioni passano da azioni_email.php, una richiesta per
 * cartella con tutti i suoi UID.
 */

const SelezioneEmail = {
    scelte: new Map(),      // chiave "cartella\nuid" → {folder, uid}
    ultima: null,           // chiave dell'ultima riga cliccata (per Maiusc+clic)
    inCorso: false,
    trascinate: null,       // [{folder, uid}] durante il trascinamento

    init() {
        const elenco = document.getElementById('emailList');
        const intestazione = document.querySelector('#sidebar .email-count');
        if (!elenco || !intestazione) return;

        const barra = document.createElement('div');
        barra.id = 'barraSelezione';
        barra.className = 'barra-selezione';
        intestazione.after(barra);
        barra.addEventListener('click', (e) => this.clicBarra(e));

        // Clic in fase di cattura: arriva prima dell'apertura dell'email
        elenco.addEventListener('click', (e) => this.clicElenco(e), true);
        new MutationObserver(() => this.elencoCambiato()).observe(elenco, { childList: true });
        this.initTrascina(elenco);
        document.addEventListener('keydown', (e) => {
            // Esc in una finestra aperta chiude quella, non la selezione (la
            // finestra "Nuova email" è sempre nella pagina, nascosta)
            const finestraAperta = [...document.querySelectorAll('.compose-modal-overlay, .search-dialog-overlay')]
                .some(o => getComputedStyle(o).display !== 'none');
            if (e.key === 'Escape' && this.scelte.size && !finestraAperta) {
                this.svuota();
            }
        });
        this.elencoCambiato();
    },

    righe() {
        return [...document.querySelectorAll('#emailList .email-item[data-file]')];
    },

    voce(riga) {
        return { folder: riga.getAttribute('data-folder') || currentFolder, uid: riga.getAttribute('data-file') };
    },

    chiave(v) {
        return `${v.folder}\n${v.uid}`;
    },

    /** L'elenco è stato ridisegnato: caselle, stelle e selezione ancora valida. */
    elencoCambiato() {
        const presenti = new Set();
        this.righe().forEach(riga => {
            const k = this.chiave(this.voce(riga));
            presenti.add(k);
            if (!riga.querySelector('.sel-casella')) {
                riga.insertAdjacentHTML('afterbegin',
                    '<span class="sel-casella" title="Seleziona (Maiusc+clic: intervallo)"><input type="checkbox" tabindex="-1" aria-label="Seleziona l\'email"></span>');
            }
            if (!riga.querySelector('.stella')) {
                const dove = riga.querySelector('.email-item-body') || riga;
                dove.insertAdjacentHTML('beforeend', '<button type="button" class="stella" tabindex="-1"></button>');
            }
            this.disegnaStella(riga);
            riga.draggable = true;
            this.segna(riga, this.scelte.has(k));
        });
        [...this.scelte.keys()].forEach(k => { if (!presenti.has(k)) this.scelte.delete(k); });
        if (this.ultima && !presenti.has(this.ultima)) this.ultima = null;
        this.aggiornaBarra();
    },

    disegnaStella(riga) {
        const on = riga.getAttribute('data-importante') === '1';
        const s = riga.querySelector('.stella');
        if (!s) return;
        s.classList.toggle('attiva', on);
        s.innerHTML = `<i class="${on ? 'fas' : 'far'} fa-star"></i>`;
        s.title = on ? 'Importante: clic per toglierla' : 'Segna come importante';
        s.setAttribute('aria-label', s.title);
    },

    segna(riga, scelta) {
        riga.classList.toggle('selezionata', scelta);
        const c = riga.querySelector('.sel-casella input');
        if (c) c.checked = scelta;
    },

    clicElenco(e) {
        const riga = e.target.closest('.email-item[data-file]');
        if (!riga) return;
        if (e.target.closest('.stella')) {
            e.preventDefault();
            e.stopPropagation();
            const on = riga.getAttribute('data-importante') !== '1';
            this.esegui([this.voce(riga)], on ? 'importante' : 'non_importante');
            return;
        }
        const suCasella = !!e.target.closest('.sel-casella');
        if (!suCasella && !(e.ctrlKey || e.metaKey)) {
            // Clic normale: apre l'email (index.php); la stella del dettaglio la segue
            this.aggiornaStellaDettaglio(riga);
            return;
        }
        // Sulla casella stessa la spunta la cambia il browser: preventDefault
        // la rimetterebbe com'era dopo questo gestore
        if (!(e.target instanceof HTMLInputElement)) e.preventDefault();
        e.stopPropagation();

        const k = this.chiave(this.voce(riga));
        const righe = this.righe();
        const scegli = !this.scelte.has(k);
        if (e.shiftKey && this.ultima !== null) {
            const da = righe.findIndex(r => this.chiave(this.voce(r)) === this.ultima);
            const a = righe.indexOf(riga);
            if (da >= 0 && a >= 0) {
                righe.slice(Math.min(da, a), Math.max(da, a) + 1).forEach(r => this.imposta(r, scegli));
            }
        } else {
            this.imposta(riga, scegli);
        }
        this.ultima = k;
        this.aggiornaBarra();
    },

    imposta(riga, scelta) {
        const v = this.voce(riga);
        if (scelta) this.scelte.set(this.chiave(v), v); else this.scelte.delete(this.chiave(v));
        this.segna(riga, scelta);
    },

    svuota() {
        this.scelte.clear();
        this.ultima = null;
        this.righe().forEach(r => this.segna(r, false));
        this.aggiornaBarra();
    },

    rigaDi(v) {
        return this.righe().find(r => r.getAttribute('data-file') === String(v.uid)
            && (r.getAttribute('data-folder') || currentFolder) === v.folder) || null;
    },

    aggiornaBarra() {
        const barra = document.getElementById('barraSelezione');
        if (!barra) return;
        const totali = this.righe().length;
        const voci = [...this.scelte.values()];
        const n = voci.length;
        barra.hidden = totali === 0;
        barra.classList.toggle('attiva', n > 0);
        const cestino = n > 0 && voci.every(v => v.folder === 'trash');
        const tutteImportanti = n > 0 && voci.every(v => this.rigaDi(v)?.getAttribute('data-importante') === '1');
        const tutte = n > 0 && n === totali;
        barra.innerHTML = `
            <label class="sel-tutte" title="${tutte ? 'Togli la selezione' : 'Seleziona tutte le email di questa pagina'}">
                <input type="checkbox" data-azione="tutte" ${tutte ? 'checked' : ''}>
                <span>${n ? (n === 1 ? '1 selezionata' : `${n} selezionate`) : 'Seleziona'}</span>
            </label>
            ${n ? `
            <div class="sel-azioni">
                <button type="button" data-azione="sposta" title="Sposta in un'altra cartella"><i class="fas fa-folder-open"></i><span>Sposta</span></button>
                ${cestino ? `
                <button type="button" data-azione="ripristina" title="Riporta nella Posta in arrivo"><i class="fas fa-undo"></i><span>Ripristina</span></button>
                <button type="button" data-azione="elimina" class="pericolo" title="Cancella per sempre"><i class="fas fa-trash-alt"></i><span>Elimina</span></button>`
                : `
                <button type="button" data-azione="cestino" title="Sposta nel Cestino"><i class="fas fa-trash-alt"></i><span>Cestino</span></button>`}
                <button type="button" data-azione="${tutteImportanti ? 'non_importante' : 'importante'}"
                    title="${tutteImportanti ? 'Togli dalle importanti' : 'Segna come importante'}"><i class="${tutteImportanti ? 'fas' : 'far'} fa-star"></i></button>
                <button type="button" data-azione="letta" title="Segna come letta"><i class="far fa-envelope-open"></i></button>
                <button type="button" data-azione="non_letta" title="Segna come non letta"><i class="far fa-envelope"></i></button>
                <button type="button" data-azione="annulla" title="Togli la selezione (Esc)"><i class="fas fa-times"></i></button>
            </div>` : ''}`;
        const casella = barra.querySelector('[data-azione="tutte"]');
        if (casella) casella.indeterminate = n > 0 && n < totali;
    },

    clicBarra(e) {
        const el = e.target.closest('[data-azione]');
        if (!el || this.inCorso) return;
        const azione = el.getAttribute('data-azione');
        const voci = [...this.scelte.values()];
        const n = voci.length;
        switch (azione) {
            case 'tutte': {
                const tutte = n > 0 && n === this.righe().length;
                this.righe().forEach(r => this.imposta(r, !tutte));
                this.aggiornaBarra();
                return;
            }
            case 'annulla':
                return this.svuota();
            case 'sposta':
                return this.scegliCartella(voci);
            case 'ripristina':
                return this.esegui(voci, 'sposta', 'inbox');
            case 'cestino': {
                // Quelle già nel Cestino (ricerca in tutte le cartelle) restano dove sono
                const daSpostare = voci.filter(v => v.folder !== 'trash');
                const m = daSpostare.length;
                if (!confirm(m === 1 ? 'Spostare l\'email selezionata nel Cestino?' : `Spostare le ${m} email selezionate nel Cestino?`)) return;
                return this.esegui(daSpostare, 'cestino');
            }
            case 'elimina':
                if (!confirm((n === 1 ? 'Cancellare per sempre l\'email selezionata?' : `Cancellare per sempre le ${n} email selezionate?`)
                    + '\n\nNon si potranno più recuperare dalla webmail.')) return;
                return this.esegui(voci, 'elimina');
            default:   // letta, non_letta, importante, non_importante
                return this.esegui(voci, azione);
        }
    },

    /** L'email aperta nel dettaglio. */
    aperta() {
        if (!currentUser || !currentEmailFile) {
            showToast('Nessuna email selezionata', 'error');
            return null;
        }
        return { folder: emailFolder || currentFolder, uid: String(currentEmailFile) };
    },

    /** "Sposta in…" del dettaglio. */
    spostaAperta() {
        const v = this.aperta();
        if (v) this.scegliCartella([v]);
    },

    /** ⭐ del dettaglio. */
    importanteAperta() {
        const v = this.aperta();
        if (!v) return;
        const btn = document.querySelector('.importante-btn');
        const on = !btn?.classList.contains('attiva');
        this.esegui([v], on ? 'importante' : 'non_importante');
    },

    /** Stella del dettaglio come quella della riga dell'email aperta. */
    aggiornaStellaDettaglio(riga) {
        const btn = document.querySelector('.importante-btn');
        if (!btn) return;
        const on = riga?.getAttribute('data-importante') === '1';
        btn.classList.toggle('attiva', on);
        btn.innerHTML = `<i class="${on ? 'fas' : 'far'} fa-star"></i>`;
        btn.title = on ? 'Togli dalle importanti' : 'Segna come importante';
    },

    account() {
        const s = document.getElementById('userSelect');
        return (s && s.value.trim()) ? s.value : currentUser;
    },

    /** Trascinamento delle email sulle cartelle del pannello (.folder-btn). */
    initTrascina(elenco) {
        elenco.addEventListener('dragstart', (e) => {
            const riga = e.target.closest?.('.email-item[data-file]');
            if (!riga) return;
            const v = this.voce(riga);
            const voci = this.scelte.has(this.chiave(v)) ? [...this.scelte.values()] : [v];
            this.trascinate = voci;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', voci.length === 1 ? '1 email' : `${voci.length} email`);
            const etichetta = document.createElement('div');
            etichetta.className = 'trascina-etichetta';
            etichetta.textContent = voci.length === 1 ? 'Sposta 1 email' : `Sposta ${voci.length} email`;
            document.body.appendChild(etichetta);
            e.dataTransfer.setDragImage(etichetta, 10, 10);
            setTimeout(() => etichetta.remove(), 0);
        });
        elenco.addEventListener('dragend', () => {
            this.trascinate = null;
            document.querySelectorAll('.folder-btn.drop-qui').forEach(b => b.classList.remove('drop-qui'));
        });

        const pannello = document.getElementById('pannelloCartelle');
        if (!pannello) return;
        // Bersaglio valido: una cartella diversa da quella di almeno una email trascinata
        const bersaglio = (e) => {
            const btn = e.target.closest('.folder-btn[data-folder]');
            if (!btn || !this.trascinate) return null;
            const dest = btn.getAttribute('data-folder');
            return this.trascinate.some(v => v.folder !== dest) ? btn : null;
        };
        pannello.addEventListener('dragover', (e) => {
            const btn = bersaglio(e);
            if (!btn) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!btn.classList.contains('drop-qui')) {
                pannello.querySelectorAll('.folder-btn.drop-qui').forEach(b => b.classList.remove('drop-qui'));
                btn.classList.add('drop-qui');
            }
        });
        pannello.addEventListener('dragleave', (e) => {
            const btn = e.target.closest('.folder-btn');
            if (btn && !btn.contains(e.relatedTarget)) btn.classList.remove('drop-qui');
        });
        pannello.addEventListener('drop', (e) => {
            const btn = bersaglio(e);
            if (!btn) return;
            e.preventDefault();
            btn.classList.remove('drop-qui');
            const dest = btn.getAttribute('data-folder');
            const voci = this.trascinate.filter(v => v.folder !== dest);
            this.trascinate = null;
            this.esegui(voci, 'sposta', dest);
        });
    },

    /** Finestra con le cartelle dell'account: un clic sposta le email. */
    async scegliCartella(voci) {
        if (!voci.length) return;
        const origini = new Set(voci.map(v => v.folder));
        document.querySelector('.sposta-overlay')?.remove();
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay sposta-overlay';
        overlay.innerHTML = `
            <div class="compose-modal sposta-modal" role="dialog" aria-modal="true" aria-labelledby="titoloSposta">
                <div class="compose-modal-header">
                    <h3 id="titoloSposta"><i class="fas fa-folder-open"></i> Sposta ${voci.length === 1 ? 'l\'email' : `${voci.length} email`} in…</h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <div class="sposta-corpo"><p class="sposta-attesa"><i class="fas fa-spinner fa-spin"></i> Caricamento cartelle…</p></div>
            </div>`;
        const chiudi = () => overlay.remove();
        overlay.querySelector('.btn-close-compose').addEventListener('click', chiudi);
        overlay.addEventListener('click', (e) => { if (e.target === overlay) chiudi(); });
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); chiudi(); } });
        document.body.appendChild(overlay);
        overlay.querySelector('.btn-close-compose').focus();

        let altre = [];
        try {
            const r = await fetch('get_folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `user=${encodeURIComponent(this.account())}`
            });
            const data = await r.json();
            if (data.error) throw new Error(data.error);
            altre = data.cartelle || [];
        } catch (err) {
            showToast('Cartelle non caricate: ' + err.message, 'error');
        }
        if (!overlay.isConnected) return;

        const icone = { inbox: 'fa-inbox', sent: 'fa-paper-plane', draft: 'fa-file-alt', junk: 'fa-ban', trash: 'fa-trash-alt' };
        const elenco = Object.keys(icone).map(id => ({ id, nome: nomeCartella(id), livello: 0, apribile: true, icona: icone[id] }))
            .concat(altre.map(c => ({ ...c, icona: 'fa-folder' })));
        const corpo = overlay.querySelector('.sposta-corpo');
        corpo.innerHTML = `<div class="sposta-elenco">${elenco.map(c => {
            // Disattivata solo se tutte le email sono già lì
            const stessa = origini.size === 1 && origini.has(c.id);
            const rientro = `style="--livello: ${Math.min(c.livello || 0, 6)}"`;
            if (!c.apribile) {
                return `<div class="sposta-voce gruppo" ${rientro}><i class="far fa-folder"></i><span>${escapeHtml(c.nome)}</span></div>`;
            }
            return `<button type="button" class="sposta-voce" data-id="${escapeHtmlAttr(c.id)}" ${rientro} ${stessa ? 'disabled title="È la cartella attuale"' : `title="${escapeHtmlAttr(c.percorso || c.nome)}"`}>`
                + `<i class="fas ${c.icona}"></i><span>${escapeHtml(c.nome)}</span>${stessa ? '<small>attuale</small>' : ''}</button>`;
        }).join('')}</div>`;
        corpo.querySelectorAll('button.sposta-voce').forEach(b => b.addEventListener('click', () => {
            chiudi();
            const dest = b.getAttribute('data-id');
            this.esegui(voci.filter(v => v.folder !== dest), 'sposta', dest);
        }));
    },

    /**
     * Esegue l'azione sulle email indicate: una richiesta per cartella.
     * Stelle e letta/non letta si aggiornano sul posto; gli spostamenti
     * ricaricano l'elenco (o ripetono la ricerca).
     */
    async esegui(voci, azione, destinazione = '') {
        if (this.inCorso || !voci.length) return;
        this.inCorso = true;
        document.getElementById('barraSelezione')?.classList.add('in-corso');
        const perCartella = new Map();
        voci.forEach(v => {
            if (!perCartella.has(v.folder)) perCartella.set(v.folder, []);
            perCartella.get(v.folder).push(v.uid);
        });
        const sulPosto = ['letta', 'non_letta', 'importante', 'non_importante'].includes(azione);
        let quante = 0;
        let ultimoMessaggio = '';
        try {
            for (const [cartella, uids] of perCartella) {
                const corpo = new URLSearchParams({ user: this.account(), folder: cartella, uids: uids.join(','), azione, destinazione });
                const r = await fetch('azioni_email.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: corpo.toString()
                });
                const data = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
                if (!data.success) throw new Error(data.error || 'Operazione non riuscita');
                quante += data.quante || 0;
                ultimoMessaggio = data.message;
            }
            // Una sola richiesta: il suo messaggio; più cartelle: il totale
            if (perCartella.size === 1) showToast(ultimoMessaggio, 'success');
            else showToast(`${quante} email ${azione === 'sposta' ? 'spostate' : 'aggiornate'}`, 'success');

            if (sulPosto) {
                voci.forEach(v => {
                    const riga = this.rigaDi(v);
                    if (!riga) return;
                    if (azione === 'importante' || azione === 'non_importante') {
                        if (azione === 'importante') riga.setAttribute('data-importante', '1');
                        else riga.removeAttribute('data-importante');
                        this.disegnaStella(riga);
                        if (riga.classList.contains('active')) this.aggiornaStellaDettaglio(riga);
                    } else {
                        riga.classList.toggle('email-new', azione === 'non_letta');
                        riga.classList.toggle('email-read', azione === 'letta');
                    }
                });
                // Stella del dettaglio anche se l'email aperta non è nell'elenco
                const a = currentEmailFile ? { folder: emailFolder || currentFolder, uid: String(currentEmailFile) } : null;
                if (a && (azione === 'importante' || azione === 'non_importante')
                    && voci.some(v => v.folder === a.folder && v.uid === a.uid)) {
                    const finta = document.createElement('div');
                    if (azione === 'importante') finta.setAttribute('data-importante', '1');
                    this.aggiornaStellaDettaglio(finta);
                }
                this.aggiornaBarra();
                return;
            }

            // L'email aperta non è più in questa cartella: si chiude il dettaglio
            if (currentEmailFile && voci.some(v => v.uid === String(currentEmailFile) && v.folder === (emailFolder || currentFolder))) {
                const dettaglio = document.getElementById('emailDetail');
                if (dettaglio) dettaglio.style.display = 'none';
                const vuoto = document.getElementById('emptyState');
                if (vuoto) vuoto.style.display = 'block';
                currentEmailFile = '';
                tornaAllElenco();
            }
            this.scelte.clear();
            this.ultima = null;
            if (typeof RicercaEmail !== 'undefined' && RicercaEmail.attiva) {
                RicercaEmail.ripeti();
            } else {
                // Pagina svuotata (ultima pagina): si torna a quella prima
                const svuotata = voci.length >= this.righe().length;
                loadEmails(svuotata && currentPage > 1 && currentPage >= totalPages ? currentPage - 1 : currentPage);
            }
        } catch (err) {
            showToast('Errore: ' + err.message, 'error');
            if (perCartella.size > 1 && !sulPosto) {
                // Alcune cartelle potrebbero essere già state fatte: si ricarica
                if (typeof RicercaEmail !== 'undefined' && RicercaEmail.attiva) RicercaEmail.ripeti(); else loadEmails(currentPage);
            }
        } finally {
            this.inCorso = false;
            document.getElementById('barraSelezione')?.classList.remove('in-corso');
        }
    },
};

document.addEventListener('DOMContentLoaded', () => SelezioneEmail.init());
