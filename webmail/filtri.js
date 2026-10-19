/**
 * Filtri sulle email in arrivo (filtri.php, lib/FiltriService.php).
 *
 * - Menu utente › Filtri: elenco nell'ordine in cui si applicano, con
 *   acceso/spento, ↑ ↓, modifica, "Applica alle email già arrivate", elimina.
 * - Lettura di un'email: il pulsante con l'imbuto apre un filtro nuovo già
 *   compilato con il mittente.
 *
 * I filtri valgono per tutte le caselle (o per quella scelta), chiunque li
 * abbia creati; li applica il server ogni minuto, anche a webmail chiusa.
 */

const FiltriEmail = {
    CAMPI: {
        mittente: 'il mittente',
        destinatari: 'i destinatari (A o Cc)',
        oggetto: "l'oggetto",
        testo: 'il testo',
        ovunque: 'oggetto, indirizzi o testo',
    },
    OPERATORI: { contiene: 'contiene', non_contiene: 'non contiene' },
    overlay: null,
    filtri: [],
    caselle: [],

    esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    async richiesta(dati) {
        const r = await fetch('filtri.php', dati ? {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(dati).toString()
        } : { cache: 'no-store' });
        const data = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
        if (!data.success) throw new Error(data.error || 'Operazione non riuscita');
        return data;
    },

    // ---------------------------------------------------------------- finestra

    costruisci() {
        if (this.overlay && document.body.contains(this.overlay)) return;
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay filtri-overlay';
        overlay.innerHTML = `
            <div class="compose-modal filtri-modal" role="dialog" aria-modal="true" aria-labelledby="titoloFiltri">
                <div class="compose-modal-header">
                    <h3 id="titoloFiltri"><i class="fas fa-filter"></i> Filtri sulle email in arrivo</h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <div class="filtri-corpo"></div>
            </div>`;
        overlay.querySelector('.btn-close-compose').addEventListener('click', () => this.chiudi());
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); this.chiudi(); } });
        document.body.appendChild(overlay);
        this.overlay = overlay;
    },

    chiudi() {
        this.overlay?.remove();
        this.overlay = null;
    },

    corpo() {
        return this.overlay.querySelector('.filtri-corpo');
    },

    async apri() {
        document.getElementById('settingsMenu')?.classList.remove('show');
        this.costruisci();
        this.corpo().innerHTML = '<p class="filtri-stato"><i class="fas fa-spinner fa-spin"></i> Caricamento...</p>';
        try {
            await this.carica();
            this.mostraElenco();
        } catch (err) {
            this.corpo().innerHTML = `<p class="filtri-stato errore">${this.esc(err.message)}</p>`;
        }
    },

    async carica() {
        const data = await this.richiesta();
        this.filtri = data.filtri || [];
        this.caselle = data.caselle || [];
    },

    // ---------------------------------------------------------------- elenco

    descriviCondizioni(f) {
        const parti = f.condizioni.map(c =>
            `${this.CAMPI[c.campo] || c.campo} ${this.OPERATORI[c.operatore] || c.operatore} «${this.esc(c.valore)}»`);
        return 'Se ' + parti.join(f.tutte ? ' <b>e</b> ' : ' <b>oppure</b> ');
    },

    descriviAzioni(f) {
        const azioni = [];
        if (f.sposta === 'trash') azioni.push('mettila nel Cestino');
        else if (f.sposta === 'junk') azioni.push("spostala nell'Indesiderata");
        else if (f.sposta) azioni.push(`spostala in <b>${this.esc(f.sposta.replace(/^cartella:/, ''))}</b>`);
        if (f.segna_letta) azioni.push('segnala come letta');
        if (f.segna_importante) azioni.push('segnala come importante ⭐');
        let s = azioni.join(', ');
        if (f.ferma) s += '; poi non applicare altri filtri';
        return s;
    },

    data(v) {
        if (!v) return '';
        const d = new Date(String(v).replace(' ', 'T'));
        return isNaN(d) ? v : d.toLocaleString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    },

    mostraElenco() {
        const righe = this.filtri.map((f, i) => {
            const info = [
                f.account_id ? `solo ${this.esc(f.account_email || 'casella ' + f.account_id)}` : 'tutte le caselle',
                f.creato_da_nome ? `creato da ${this.esc(f.creato_da_nome)}` : '',
                f.applicate === 1 ? `1 email filtrata, il ${this.data(f.ultima_applicazione)}`
                    : f.applicate ? `${f.applicate} email filtrate, l'ultima il ${this.data(f.ultima_applicazione)}` : 'nessuna email filtrata finora',
                f.applica_esistenti ? '<span class="filtri-attesa"><i class="fas fa-hourglass-half"></i> in coda per le email già arrivate</span>' : '',
            ].filter(Boolean).join(' · ');
            return `
                <li class="filtri-voce${f.attivo ? '' : ' spento'}" data-id="${f.id}">
                    <label class="filtri-interruttore" title="${f.attivo ? 'Acceso: clicca per spegnerlo' : 'Spento: clicca per accenderlo'}">
                        <input type="checkbox" data-azione="attiva" ${f.attivo ? 'checked' : ''}><span></span>
                    </label>
                    <div class="filtri-testo">
                        <strong>${i + 1}. ${this.esc(f.nome)}</strong>
                        <div>${this.descriviCondizioni(f)}: ${this.descriviAzioni(f)}.</div>
                        <small>${info}</small>
                        ${f.ultimo_errore ? `<small class="filtri-errore"><i class="fas fa-exclamation-triangle"></i> ${this.esc(f.ultimo_errore)}</small>` : ''}
                    </div>
                    <div class="filtri-bottoni">
                        <button type="button" data-azione="su" title="Più in alto (si applica prima)" ${i === 0 ? 'disabled' : ''}><i class="fas fa-arrow-up"></i></button>
                        <button type="button" data-azione="giu" title="Più in basso" ${i === this.filtri.length - 1 ? 'disabled' : ''}><i class="fas fa-arrow-down"></i></button>
                        <button type="button" data-azione="modifica" title="Modifica"><i class="fas fa-pen"></i></button>
                        <button type="button" data-azione="esistenti" title="Applica anche alle email già nella Posta in arrivo" ${f.attivo ? '' : 'disabled'}><i class="fas fa-play"></i></button>
                        <button type="button" data-azione="elimina" class="pericolo" title="Elimina"><i class="fas fa-trash-alt"></i></button>
                    </div>
                </li>`;
        }).join('');

        this.corpo().innerHTML = `
            <p class="filtri-nota">I filtri valgono per <b>tutte le caselle</b>, chiunque li abbia creati, e si applicano
                nell'ordine dell'elenco alle email che arrivano nella <b>Posta in arrivo</b>, entro un minuto, anche
                a webmail chiusa. Nessun filtro cancella le email per sempre: al massimo le mette nel Cestino.</p>
            ${righe ? `<ol class="filtri-elenco">${righe}</ol>` : '<p class="filtri-stato">Nessun filtro, per ora.</p>'}
            <div class="filtri-azioni">
                <button type="button" class="archivia-btn-primario" data-azione="nuovo"><i class="fas fa-plus"></i> Nuovo filtro</button>
            </div>`;

        this.corpo().querySelector('[data-azione="nuovo"]').addEventListener('click', () => this.mostraForm());
        this.corpo().querySelectorAll('.filtri-voce').forEach(li => {
            const f = this.filtri.find(x => String(x.id) === li.dataset.id);
            li.querySelectorAll('[data-azione]').forEach(el => {
                el.addEventListener(el.type === 'checkbox' ? 'change' : 'click', () => this.azione(el.dataset.azione, f, el));
            });
        });
    },

    async azione(azione, f, el) {
        if (azione === 'modifica') {
            this.mostraForm(f);
            return;
        }
        if (azione === 'elimina' && !confirm(`Eliminare il filtro "${f.nome}"?`)) return;
        if (azione === 'esistenti' && !confirm(`Applicare il filtro "${f.nome}" anche a tutte le email già nella Posta in arrivo`
                + (f.account_id ? ' della casella scelta' : ' di tutte le caselle') + '?')) return;
        const dati = {
            attiva: { azione: 'attiva', id: f.id, attivo: el?.checked ? '1' : '0' },
            su: { azione: 'sposta', id: f.id, verso: '-1' },
            giu: { azione: 'sposta', id: f.id, verso: '1' },
            esistenti: { azione: 'esistenti', id: f.id },
            elimina: { azione: 'elimina', id: f.id },
        }[azione];
        if (!dati) return;
        try {
            const data = await this.richiesta(dati);
            if (azione !== 'su' && azione !== 'giu') showToast(data.message, 'success');
            await this.carica();
            this.mostraElenco();
        } catch (err) {
            showToast('Errore: ' + err.message, 'error');
            if (el?.type === 'checkbox') el.checked = !el.checked;
        }
    },

    // ---------------------------------------------------------------- modulo

    rigaCondizione(c = {}) {
        const opzioni = (mappa, scelto) => Object.entries(mappa)
            .map(([k, v]) => `<option value="${k}"${k === scelto ? ' selected' : ''}>${this.esc(v)}</option>`).join('');
        const riga = document.createElement('div');
        riga.className = 'filtri-condizione';
        riga.innerHTML = `
            <select data-campo="campo" aria-label="Dove guardare">${opzioni(this.CAMPI, c.campo || 'mittente')}</select>
            <select data-campo="operatore" aria-label="Come confrontare">${opzioni(this.OPERATORI, c.operatore || 'contiene')}</select>
            <input type="text" data-campo="valore" maxlength="200" placeholder="es. rossi@esempio.it" value="${this.esc(c.valore || '')}" aria-label="Testo">
            <button type="button" class="filtri-togli" title="Togli questa condizione"><i class="fas fa-times"></i></button>`;
        riga.querySelector('.filtri-togli').addEventListener('click', () => {
            const tutte = riga.parentElement;
            if (tutte.children.length > 1) riga.remove();
            else riga.querySelector('input').value = '';
        });
        return riga;
    },

    mostraForm(f = null, proposta = null) {
        const v = f || proposta || {};
        const sposta = v.sposta || '';
        const tipoSposta = !sposta ? '' : (sposta === 'trash' || sposta === 'junk') ? sposta : 'cartella';
        const cartella = tipoSposta === 'cartella' ? sposta.replace(/^cartella:/, '') : '';
        const cartelleNote = [...new Set(Object.values(typeof altreCartelle === 'object' ? altreCartelle : {}))]
            .map(p => `<option value="${this.esc(p)}"></option>`).join('');
        const caselle = ['<option value="">Tutte le caselle</option>']
            .concat(this.caselle.map(c => `<option value="${c.id}"${Number(v.account_id) === c.id ? ' selected' : ''}>`
                + `Solo ${this.esc(c.email)}</option>`));
        if (v.account_id && !this.caselle.some(c => c.id === Number(v.account_id))) {
            caselle.push(`<option value="${v.account_id}" selected>Solo ${this.esc(v.account_email || 'casella ' + v.account_id)}</option>`);
        }
        const radio = (val, testo) => `<label class="filtri-scelta"><input type="radio" name="filtroSposta" value="${val}"${tipoSposta === val ? ' checked' : ''}> ${testo}</label>`;

        this.corpo().innerHTML = `
            <form class="filtri-form" novalidate>
                <h4>${f ? 'Modifica il filtro' : 'Nuovo filtro'}</h4>
                <div class="filtri-due">
                    <label>Nome<input type="text" name="nome" maxlength="100" required value="${this.esc(v.nome || '')}" placeholder="es. Fatture del fornitore Rossi"></label>
                    <label>Per quali caselle<select name="account_id">${caselle.join('')}</select></label>
                </div>

                <fieldset>
                    <legend>Quando l'email</legend>
                    <div class="filtri-quando">
                        <label class="filtri-scelta"><input type="radio" name="tutte" value="1"${v.tutte === 0 ? '' : ' checked'}> <span>rispetta <b>tutte</b> le condizioni</span></label>
                        <label class="filtri-scelta"><input type="radio" name="tutte" value="0"${v.tutte === 0 ? ' checked' : ''}> <span>ne rispetta <b>almeno una</b></span></label>
                    </div>
                    <div class="filtri-condizioni"></div>
                    <button type="button" class="filtri-link" data-azione="aggiungi"><i class="fas fa-plus"></i> Aggiungi una condizione</button>
                    <p class="filtri-nota piccola">"Contiene" non distingue maiuscole e minuscole e vale anche per una parte:
                        «rossi» trova sia «Mario Rossi» sia «info@rossi.it».</p>
                </fieldset>

                <fieldset>
                    <legend>Cosa fare</legend>
                    ${radio('', 'Lasciala nella Posta in arrivo')}
                    <div class="filtri-cartella">
                        ${radio('cartella', 'Spostala nella cartella')}
                        <input type="text" name="cartella" list="filtriCartelle" maxlength="300" value="${this.esc(cartella)}" placeholder="es. Fornitori / Rossi">
                        <datalist id="filtriCartelle">${cartelleNote}</datalist>
                    </div>
                    ${radio('junk', "Spostala nell'Indesiderata")}
                    ${radio('trash', 'Mettila nel Cestino')}
                    <div class="filtri-spunte">
                        <label class="filtri-scelta"><input type="checkbox" name="segna_letta"${v.segna_letta ? ' checked' : ''}> Segnala come letta</label>
                        <label class="filtri-scelta"><input type="checkbox" name="segna_importante"${v.segna_importante ? ' checked' : ''}> Segnala come importante ⭐</label>
                        <label class="filtri-scelta"><input type="checkbox" name="ferma"${v.ferma ? ' checked' : ''}> Dopo questo filtro non applicare gli altri</label>
                    </div>
                    <p class="filtri-nota piccola">Se la cartella non esiste in una casella, viene creata la prima volta che serve.
                        Per scendere di livello usa la barra: «Fornitori / Rossi». Sulle caselle copiate in locale lo
                        spostamento vale solo per la copia sul server, come quelli fatti a mano.</p>
                </fieldset>

                <p class="filtri-errore-form" hidden></p>
                <div class="filtri-azioni">
                    <button type="button" class="archivia-btn-secondario" data-azione="annulla">Annulla</button>
                    <button type="submit" class="archivia-btn-primario"><i class="fas fa-check"></i> ${f ? 'Salva' : 'Crea il filtro'}</button>
                </div>
            </form>`;

        const form = this.corpo().querySelector('form');
        const condizioni = form.querySelector('.filtri-condizioni');
        (v.condizioni && v.condizioni.length ? v.condizioni : [{}]).forEach(c => condizioni.appendChild(this.rigaCondizione(c)));
        form.querySelector('[data-azione="aggiungi"]').addEventListener('click', () => {
            if (condizioni.children.length >= 10) return;
            const r = this.rigaCondizione();
            condizioni.appendChild(r);
            r.querySelector('input').focus();
        });
        // Scrivere una cartella sceglie lo spostamento
        form.cartella.addEventListener('input', () => {
            form.querySelector('input[name="filtroSposta"][value="cartella"]').checked = true;
        });
        form.querySelector('[data-azione="annulla"]').addEventListener('click', () =>
            this.filtri.length || f ? this.mostraElenco() : this.chiudi());
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.salva(form, f);
        });
        (form.nome.value ? condizioni.querySelector('input') : form.nome).focus();
    },

    async salva(form, f) {
        const errore = form.querySelector('.filtri-errore-form');
        const tipo = form.querySelector('input[name="filtroSposta"]:checked')?.value || '';
        const dati = {
            id: f ? f.id : 0,
            nome: form.nome.value,
            account_id: form.account_id.value || null,
            tutte: form.querySelector('input[name="tutte"]:checked').value === '1',
            condizioni: [...form.querySelectorAll('.filtri-condizione')].map(r => ({
                campo: r.querySelector('[data-campo="campo"]').value,
                operatore: r.querySelector('[data-campo="operatore"]').value,
                valore: r.querySelector('[data-campo="valore"]').value,
            })),
            sposta: tipo === 'cartella' ? form.cartella.value.trim() : tipo,
            segna_letta: form.segna_letta.checked,
            segna_importante: form.segna_importante.checked,
            ferma: form.ferma.checked,
            attivo: f ? !!f.attivo : true,
        };
        if (tipo === 'cartella' && !dati.sposta) {
            errore.textContent = 'Scrivi la cartella in cui spostare le email';
            errore.hidden = false;
            form.cartella.focus();
            return;
        }
        const invia = form.querySelector('[type="submit"]');
        invia.disabled = true;
        try {
            const data = await this.richiesta({ azione: 'salva', dati: JSON.stringify(dati) });
            showToast(data.message, 'success');
            await this.carica();
            this.mostraElenco();
        } catch (err) {
            errore.textContent = err.message;
            errore.hidden = false;
            invia.disabled = false;
        }
    },

    /** Dal pulsante nella lettura: filtro nuovo con il mittente dell'email aperta. */
    async daEmail() {
        const mittente = (document.getElementById('detailFrom')?.textContent || '').trim();
        const indirizzo = (mittente.match(/<([^<>\s]+@[^<>\s]+)>/) || mittente.match(/[^\s<>"]+@[^\s<>"]+/) || [])[1]
            || (mittente.match(/[^\s<>"]+@[^\s<>"]+/) || [''])[0];
        this.costruisci();
        this.corpo().innerHTML = '<p class="filtri-stato"><i class="fas fa-spinner fa-spin"></i> Caricamento...</p>';
        try {
            await this.carica();
        } catch (err) {
            this.corpo().innerHTML = `<p class="filtri-stato errore">${this.esc(err.message)}</p>`;
            return;
        }
        this.mostraForm(null, {
            nome: indirizzo ? `Email da ${indirizzo}` : '',
            condizioni: [{ campo: 'mittente', operatore: 'contiene', valore: indirizzo || mittente }],
            tutte: 1,
        });
    },
};
