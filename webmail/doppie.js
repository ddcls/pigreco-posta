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
 * Impostazioni › Email doppie (solo amministratore): cerca in una cartella le email presenti più volte e
 * sposta nel Cestino le copie in più (doppie.php, MailboxService::duplicates()). Si tiene la copia arrivata
 * per prima, che prende i segni letta/importante delle altre; mai una cancellazione definitiva.
 */

const DoppieEmail = {
    SPECIALI: [
        ['inbox', 'Posta in arrivo'], ['sent', 'Inviata'], ['draft', 'Bozze'],
        ['junk', 'Indesiderata'], ['trash', 'Cestino'],
    ],
    /** Righe mostrate al massimo nell'elenco (il conteggio resta quello vero). */
    MAX_RIGHE: 500,
    pannello: null,
    risultato: null,

    esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    },

    numero(n) {
        return Number(n).toLocaleString('it-IT');
    },

    async richiesta(dati) {
        const r = await fetch('doppie.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(dati).toString()
        });
        const data = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
        if (!data.success) throw new Error(data.error || 'Operazione non riuscita');
        return data;
    },

    /** Costruisce il pannello (una volta) con le caselle della select #userSelect. */
    mostra(pannello) {
        if (!pannello) return;
        this.pannello = pannello;
        if (!pannello.dataset.pronto) {
            pannello.dataset.pronto = '1';
            pannello.innerHTML = `
                <p class="settings-panel-hint">
                    Cerca in una cartella le email presenti più volte: stesso Message-ID e stesso oggetto (senza
                    Message-ID: stessi data, mittente, oggetto e grandezza). Di ogni email si tiene la copia
                    arrivata per prima, che prende i segni <em>letta</em> e <em>importante</em> delle altre;
                    le copie in più vanno nel <strong>Cestino</strong>, da dove si possono ancora recuperare.
                </p>
                <div class="doppie-scelta">
                    <label>Casella <select class="doppie-casella"></select></label>
                    <label>Cartella <select class="doppie-cartella"></select></label>
                    <button type="button" class="btn-identita-refresh doppie-cerca"><i class="fas fa-search"></i> Cerca doppioni</button>
                </div>
                <div class="doppie-esito"></div>`;
            pannello.querySelector('.doppie-casella').addEventListener('change', () => this.caricaCartelle());
            pannello.querySelector('.doppie-cartella').addEventListener('change', () => this.svuota());
            pannello.querySelector('.doppie-cerca').addEventListener('click', () => this.cerca());
        }
        const sel = pannello.querySelector('.doppie-casella');
        const scelta = sel.value;
        sel.innerHTML = '';
        document.querySelectorAll('#userSelect option').forEach(o => {
            if (!o.value || o.disabled) return;
            sel.appendChild(new Option(o.textContent, o.value));
        });
        sel.value = scelta || document.getElementById('userSelect')?.value || sel.value;
        this.caricaCartelle();
    },

    svuota() {
        this.risultato = null;
        this.pannello.querySelector('.doppie-esito').innerHTML = '';
    },

    async caricaCartelle() {
        const casella = this.pannello.querySelector('.doppie-casella').value;
        const sel = this.pannello.querySelector('.doppie-cartella');
        const scelta = sel.value;
        this.svuota();
        sel.innerHTML = '';
        this.SPECIALI.forEach(([id, nome]) => sel.appendChild(new Option(nome, id)));
        if (!casella) return;
        try {
            const r = await fetch('get_folders.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ account_id: casella }).toString()
            });
            const data = await r.json();
            (data.cartelle || []).filter(c => c.apribile).forEach(c => sel.appendChild(new Option(c.percorso, c.id)));
        } catch (e) {
            showToast('Cartelle non lette: ' + e.message, 'error');
        }
        if ([...sel.options].some(o => o.value === scelta)) sel.value = scelta;
    },

    nomeCartella() {
        const sel = this.pannello.querySelector('.doppie-cartella');
        return sel.options[sel.selectedIndex]?.textContent || '';
    },

    async cerca() {
        const esito = this.pannello.querySelector('.doppie-esito');
        const bottone = this.pannello.querySelector('.doppie-cerca');
        const dati = {
            azione: 'cerca',
            account_id: this.pannello.querySelector('.doppie-casella').value,
            folder: this.pannello.querySelector('.doppie-cartella').value,
        };
        if (!dati.account_id) { showToast('Scegli una casella', 'error'); return; }
        bottone.disabled = true;
        esito.innerHTML = '<p class="doppie-stato"><i class="fas fa-spinner fa-spin"></i> Controllo delle email in corso: '
            + 'con molte email può volerci qualche minuto…</p>';
        try {
            const r = await this.richiesta(dati);
            this.risultato = { ...dati, ...r };
            this.disegna();
        } catch (e) {
            esito.innerHTML = `<p class="doppie-stato errore">${this.esc(e.message)}</p>`;
        } finally {
            bottone.disabled = false;
        }
    },

    disegna() {
        const r = this.risultato;
        const esito = this.pannello.querySelector('.doppie-esito');
        const cartella = this.esc(this.nomeCartella());
        if (!r.gruppi.length) {
            esito.innerHTML = `<p class="doppie-stato ok"><i class="fas fa-check"></i> Nessuna email doppia in
                <strong>${cartella}</strong> (${this.numero(r.totale)} email controllate).</p>`;
            return;
        }
        const righe = r.gruppi.slice(0, this.MAX_RIGHE).map(g => `
            <tr>
                <td>${this.esc(g.data)}</td>
                <td>${this.esc(g.mittente)}</td>
                <td>${this.esc(g.oggetto || '(senza oggetto)')}</td>
                <td class="doppie-num">${g.copie.length + 1}</td>
            </tr>`).join('');
        const altre = r.gruppi.length > this.MAX_RIGHE
            ? `<p class="doppie-nota">Mostrate le prime ${this.MAX_RIGHE}: il pulsante sposta le copie di tutte.</p>` : '';
        const cestino = r.folder === 'trash'
            ? '<p class="doppie-nota">Le copie sono nel Cestino: lì si cancellano a mano.</p>'
            : `<button type="button" class="btn-identita-add doppie-sposta"><i class="fas fa-trash-alt"></i>
                Sposta ${this.numero(r.copie)} ${r.copie === 1 ? 'copia' : 'copie'} nel Cestino</button>`;
        esito.innerHTML = `
            <p class="doppie-stato"><strong>${this.numero(r.gruppi.length)}</strong>
                ${r.gruppi.length === 1 ? 'email ha' : 'email hanno'} delle copie in <strong>${cartella}</strong>:
                <strong>${this.numero(r.copie)}</strong> ${r.copie === 1 ? 'copia' : 'copie'} in più
                su ${this.numero(r.totale)} email.</p>
            ${cestino}
            <div class="accounts-table-container doppie-tabella">
                <table class="accounts-table">
                    <thead><tr><th>Data</th><th>Mittente</th><th>Oggetto</th><th class="doppie-num">Copie</th></tr></thead>
                    <tbody>${righe}</tbody>
                </table>
            </div>
            ${altre}`;
        esito.querySelector('.doppie-sposta')?.addEventListener('click', () => this.sposta());
    },

    async sposta() {
        const r = this.risultato;
        const n = this.numero(r.copie);
        if (!confirm(`Spostare nel Cestino ${n} ${r.copie === 1 ? 'copia' : 'copie'} di "${this.nomeCartella()}"?\n`
            + 'Di ogni email resta la copia arrivata per prima.')) return;
        const bottone = this.pannello.querySelector('.doppie-sposta');
        bottone.disabled = true;
        bottone.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Spostamento in corso…';
        try {
            const esito = await this.richiesta({ azione: 'cestino', account_id: r.account_id, folder: r.folder });
            showToast(esito.message, 'success');
            if (typeof loadEmails === 'function') loadEmails();
            await this.cerca();
        } catch (e) {
            showToast('Errore: ' + e.message, 'error');
            bottone.disabled = false;
        }
    },
};
