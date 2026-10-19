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
 * Ricerca sul server di posta (cerca_email.php, IMAP SEARCH).
 *
 * Prima la ricerca guardava solo le email della pagina (e il "Corpo" non
 * funzionava: l'elenco non contiene il testo). Ora cerca in tutta la
 * cartella aperta o in tutte le cartelle dell'account; i risultati vanno
 * nell'elenco centrale con la stessa struttura delle righe normali (spunte,
 * stelle, trascinamento, apertura) e, cercando dappertutto, il nome della
 * cartella. "Altri risultati" in fondo carica la pagina successiva.
 *
 * La finestra "Ricerca Email" di index.php resta quella: executeSearch()
 * chiama RicercaEmail.cerca(). Ogni loadEmails() (cambio cartella, account,
 * pagina) chiude la ricerca.
 */

const RicercaEmail = {
    attiva: false,
    parametri: null,    // {q, dove, campi, soloImportanti}
    pagina: 0,
    totale: 0,
    turno: 0,           // per ignorare risposte di ricerche superate

    init() {
        // Ogni caricamento normale dell'elenco chiude la ricerca
        if (typeof loadEmails === 'function') {
            const originale = loadEmails;
            window.loadEmails = function (...argomenti) {
                RicercaEmail.disattiva();
                return originale.apply(this, argomenti);
            };
        }
    },

    account() {
        const s = document.getElementById('userSelect');
        return (s && s.value.trim()) ? s.value : currentUser;
    },

    perPagina() {
        const v = parseInt(document.getElementById('perPageSelect')?.value || '50', 10);
        return v > 0 ? Math.min(v, 200) : 200;
    },

    /** Dalla finestra "Ricerca Email" (executeSearch). */
    cerca() {
        const q = (document.getElementById('searchQuery')?.value || '').trim();
        const campi = [];
        if (document.getElementById('searchEmail')?.checked) campi.push('mittente', 'destinatari');
        if (document.getElementById('searchSubject')?.checked) campi.push('oggetto');
        if (document.getElementById('searchBody')?.checked) campi.push('corpo');
        const soloImportanti = !!document.getElementById('searchImportanti')?.checked;
        const dove = document.getElementById('searchDove')?.value === 'tutte' ? 'tutte' : 'qui';

        if (!this.account()) {
            showToast('Scegli prima un account', 'error');
            return;
        }
        if (!q && !soloImportanti) {
            showToast('Scrivi il testo da cercare', 'error');
            return;
        }
        if (q && !campi.length) {
            showToast('Scegli almeno un campo in cui cercare', 'error');
            return;
        }
        this.parametri = { q, campi, soloImportanti, dove, cartella: currentFolder };
        closeSearchDialog();
        document.getElementById('searchInput').value = q;
        this.attiva = true;
        this.pagina = 0;
        this.carica(false);
    },

    /** Dopo spostamenti/cancellazioni: stessa ricerca, quante righe c'erano. */
    ripeti() {
        if (!this.attiva) return;
        this.pagina = 0;
        this.carica(false);
    },

    disattiva() {
        if (!this.attiva) return;
        this.attiva = false;
        this.parametri = null;
        document.querySelector('#sidebar .pagination-controls')?.removeAttribute('hidden');
    },

    annulla() {
        this.disattiva();
        if (typeof clearSearch === 'function') clearSearch(); else loadEmails(currentPage);
    },

    async carica(aggiungi) {
        const p = this.parametri;
        if (!p) return;
        const turno = ++this.turno;
        const elenco = document.getElementById('emailList');
        const conteggio = document.getElementById('emailCount');
        const altri = elenco.querySelector('.ricerca-altri');
        document.querySelector('#sidebar .pagination-controls')?.setAttribute('hidden', '');
        if (aggiungi) {
            if (altri) altri.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Caricamento…';
        } else {
            elenco.innerHTML = `<div class="ricerca-attesa"><div class="spinner"></div>
                <p>Ricerca in corso…${p.campi.includes('corpo') ? '<br><small>Cercare nel testo delle email può richiedere qualche secondo.</small>' : ''}</p></div>`;
        }
        try {
            const corpo = new URLSearchParams({
                user: this.account(),
                folder: p.dove === 'tutte' ? 'tutte' : p.cartella,
                q: p.q,
                campi: p.campi.join(','),
                solo_importanti: p.soloImportanti ? '1' : '',
                page: String(this.pagina + 1),
                limit: String(this.perPagina()),
            });
            const r = await fetch('cerca_email.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: corpo.toString()
            });
            const data = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
            if (turno !== this.turno || !this.attiva) return;
            if (data.error) throw new Error(data.error);
            this.pagina += 1;
            this.totale = data.total || 0;

            if (!aggiungi) elenco.innerHTML = '';
            elenco.querySelector('.ricerca-altri')?.remove();
            if (!this.totale) {
                elenco.innerHTML = `<div class="no-emails"><i class="fas fa-search"></i>
                    <p>Nessuna email trovata${p.q ? ` per <strong>«${escapeHtml(p.q)}»</strong>` : ''}.</p>
                    <button type="button" class="retry-btn" data-azione="annulla-ricerca"><i class="fas fa-times"></i> Annulla ricerca</button></div>`;
            } else {
                elenco.insertAdjacentHTML('beforeend', data.emails.map(e => this.riga(e)).join(''));
                const mostrate = elenco.querySelectorAll('.email-item[data-file]').length;
                if (mostrate < this.totale) {
                    elenco.insertAdjacentHTML('beforeend', `<button type="button" class="ricerca-altri" data-azione="altri">
                        <i class="fas fa-chevron-down"></i> Altri risultati (${(this.totale - mostrate).toLocaleString('it-IT')})</button>`);
                }
            }
            elenco.querySelectorAll('.email-item[data-file]:not([data-collegata])').forEach(riga => {
                riga.setAttribute('data-collegata', '1');
                riga.addEventListener('click', () => showEmailDetails(riga.getAttribute('data-file'), riga));
            });
            elenco.querySelectorAll('[data-azione="annulla-ricerca"]').forEach(b => b.addEventListener('click', () => this.annulla()));
            elenco.querySelector('[data-azione="altri"]')?.addEventListener('click', () => this.carica(true));

            const dove = p.dove === 'tutte' ? 'in tutte le cartelle' : `in ${nomeCartella(p.cartella)}`;
            const cosa = [p.q ? `«${p.q}»` : '', p.soloImportanti ? '⭐ importanti' : ''].filter(Boolean).join(' · ');
            conteggio.innerHTML = `<span class="elenco-cartella" title="${escapeHtmlAttr(cosa + ' ' + dove)}">Ricerca: ${escapeHtml(cosa)}</span>`
                + `<span class="elenco-totale">${this.totale.toLocaleString('it-IT')} ${this.totale === 1 ? 'trovata' : 'trovate'} ${escapeHtml(dove)}`
                + `${data.troncata ? ' (le più recenti)' : ''} · <button type="button" class="ricerca-annulla" title="Torna all'elenco">✕ Annulla</button></span>`;
            conteggio.querySelector('.ricerca-annulla').addEventListener('click', () => this.annulla());
        } catch (err) {
            if (turno !== this.turno) return;
            showToast('Ricerca non riuscita: ' + err.message, 'error');
            if (!aggiungi) {
                elenco.innerHTML = `<div class="no-emails"><i class="fas fa-exclamation-triangle"></i><p>${escapeHtml(err.message)}</p>
                    <button type="button" class="retry-btn" data-azione="annulla-ricerca"><i class="fas fa-times"></i> Annulla ricerca</button></div>`;
                elenco.querySelector('[data-azione="annulla-ricerca"]').addEventListener('click', () => this.annulla());
            }
        }
    },

    /** Riga come quelle di loadEmails (index.php), con la cartella se si cerca dappertutto. */
    riga(e) {
        const p = this.parametri;
        const uscita = e.folder === 'sent' || e.folder === 'draft';
        const chi = (uscita && e.recipient ? 'A: ' + e.recipient : e.sender) || 'Sconosciuto';
        const oggetto = e.subject || '(nessun oggetto)';
        const cartella = p.dove === 'tutte'
            ? `<span class="ricerca-cartella" title="Cartella">${escapeHtml(nomeCartella(e.folder))}</span>` : '';
        const allegato = e.has_attachments ? '<span class="attachment-indicator" title="Ha allegati"><i class="fas fa-paperclip"></i></span>' : '';
        const oggettoHtml = p.q && p.campi.includes('oggetto') ? highlightSearchTerm(escapeHtml(oggetto), p.q) : escapeHtml(oggetto);
        return `
            <div class="email-item ${e.is_new ? 'email-new' : 'email-read'}" data-file="${escapeHtmlAttr(e.file)}"
                data-folder="${escapeHtmlAttr(e.folder)}" ${e.is_flagged ? 'data-importante="1"' : ''}>
                <div class="email-item-header">
                    <div class="email-sender-container">
                        <div class="email-sender-text" title="${escapeHtmlAttr(chi)}">${escapeHtml(chi)}</div>
                        <div class="email-icons-container">${cartella}${allegato}</div>
                    </div>
                    <div class="email-date">${escapeHtml(e.date || '')}</div>
                </div>
                <div class="email-item-body">
                    <div class="email-subject-preview" title="${escapeHtmlAttr(oggetto)}">${oggettoHtml}</div>
                </div>
            </div>`;
    },
};

document.addEventListener('DOMContentLoaded', () => RicercaEmail.init());
