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
 * Allegati XML leggibili senza scaricarli (xml_allegato.php, lib/XmlAllegato.php).
 *
 * L'occhio 👁️ sugli allegati .xml e .xml.p7m apre una finestra con:
 * - le ricevute della PEC (daticert.xml) in italiano chiaro;
 * - le notifiche dello SdI (RC, MC, NS, NE, DT, AT, EC, SE, MT);
 * - il riepilogo delle fatture elettroniche (anche firmate .p7m);
 * - per ogni XML il testo ordinato ("Mostra l'XML") e il pulsante Scarica.
 *
 * Il server manda solo testi: qui vanno nel DOM con textContent, mai come
 * HTML (il contenuto viene dal mittente).
 */
const AllegatoXml = {
    overlay: null,
    richiesta: 0,

    ICONE: { ok: 'fa-circle-check', errore: 'fa-circle-xmark', attenzione: 'fa-triangle-exclamation', info: 'fa-circle-info' },

    /** Allegato da aprire con questa finestra? */
    eXml(nome) {
        return /\.xml(\.p7m)?$/i.test(String(nome || '').trim());
    },

    /** @param {{user:string, file:string, folder:string, index:number, nome:string}} a */
    async apri(a) {
        this.costruisci();
        const n = ++this.richiesta;
        this.mostraTitolo(a.nome || 'Allegato XML');
        this.corpo().replaceChildren(this.el('div', 'xml-stato', "Lettura dell'allegato…"));
        this.scarica = () => downloadAttachmentViaFetch(a.user, a.file, String(a.index), a.folder, a.nome);
        this.overlay.style.display = 'flex';
        this.overlay.querySelector('.btn-close-compose').focus();

        const url = 'xml_allegato.php?' + new URLSearchParams({
            user: a.user, file: a.file, index: String(a.index), folder: a.folder,
        });
        let dati;
        try {
            const risposta = await fetch(url, { credentials: 'same-origin' });
            dati = await risposta.json().catch(() => ({ success: false, error: 'Risposta non valida dal server' }));
            if (!risposta.ok && dati.success !== false) dati = { success: false, error: 'Errore ' + risposta.status };
        } catch (err) {
            dati = { success: false, error: 'Connessione non riuscita' };
        }
        if (n !== this.richiesta || !this.overlay) return; // nel frattempo aperto un altro
        if (!dati.success) {
            this.corpo().replaceChildren(this.el('div', 'xml-stato errore', dati.error || 'Allegato non leggibile'));
            return;
        }
        this.disegna(dati);
    },

    disegna(d) {
        const corpo = this.corpo();
        corpo.replaceChildren();

        const esito = this.ICONE[d.esito] ? d.esito : 'info';
        const testata = this.el('div', 'xml-esito xml-esito-' + esito);
        const icona = this.el('i', 'fas ' + this.ICONE[esito]);
        icona.setAttribute('aria-hidden', 'true');
        const testi = this.el('div', 'xml-esito-testi');
        testi.append(this.el('div', 'xml-esito-titolo', d.titolo || 'File XML'));
        if (d.frase) testi.append(this.el('div', 'xml-esito-frase', d.frase));
        testata.append(icona, testi);
        corpo.append(testata);

        for (const avviso of d.avvisi || []) {
            corpo.append(this.el('div', 'xml-avviso', avviso));
        }

        if ((d.righe || []).length) corpo.append(this.tabella(d.righe));
        for (const s of d.sezioni || []) {
            corpo.append(this.el('h4', 'xml-sezione', s.titolo));
            corpo.append(this.tabella(s.righe || []));
        }

        if (d.testo) {
            const dettagli = document.createElement('details');
            dettagli.className = 'xml-testo';
            // Un XML che non si sa leggere si mostra subito
            if (d.tipo === 'xml' && !(d.righe || []).length) dettagli.open = true;
            dettagli.append(this.el('summary', '', "Mostra l'XML"), this.el('pre', '', d.testo));
            corpo.append(dettagli);
        }
    },

    tabella(righe) {
        const dl = this.el('dl', 'xml-righe');
        for (const [etichetta, valore] of righe) {
            dl.append(this.el('dt', '', etichetta), this.el('dd', '', valore));
        }
        return dl;
    },

    el(tag, classe, testo) {
        const e = document.createElement(tag);
        if (classe) e.className = classe;
        if (testo !== undefined) e.textContent = testo;
        return e;
    },

    mostraTitolo(nome) {
        this.overlay.querySelector('.xml-titolo-nome').textContent = nome;
    },

    corpo() {
        return this.overlay.querySelector('.xml-corpo');
    },

    costruisci() {
        if (this.overlay && document.body.contains(this.overlay)) return;
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay xml-overlay';
        overlay.style.display = 'none';
        overlay.innerHTML = `
            <div class="compose-modal xml-modal" role="dialog" aria-modal="true" aria-labelledby="titoloXmlAllegato">
                <div class="compose-modal-header">
                    <h3 id="titoloXmlAllegato"><i class="fas fa-file-code"></i> <span class="xml-titolo-nome"></span></h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <div class="xml-corpo"></div>
                <div class="xml-piede">
                    <button type="button" class="xml-scarica"><i class="fas fa-download"></i> Scarica</button>
                    <button type="button" class="xml-chiudi">Chiudi</button>
                </div>
            </div>`;
        overlay.querySelector('.btn-close-compose').addEventListener('click', () => this.chiudi());
        overlay.querySelector('.xml-chiudi').addEventListener('click', () => this.chiudi());
        overlay.querySelector('.xml-scarica').addEventListener('click', () => this.scarica && this.scarica());
        overlay.addEventListener('click', (e) => { if (e.target === overlay) this.chiudi(); });
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); this.chiudi(); } });
        document.body.appendChild(overlay);
        this.overlay = overlay;
    },

    chiudi() {
        this.richiesta++;
        if (this.overlay) this.overlay.style.display = 'none';
    },
};
