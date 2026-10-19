/**
 * Bozze: "Salva bozza", salvataggio automatico e "Modifica bozza".
 *
 * - La bozza è il messaggio che verrebbe spedito, costruito da
 *   send_email.php con bozza=1 e salvato nella cartella Bozze (\Draft);
 *   bozza_uid indica la versione precedente, che il server cancella: resta
 *   sempre una sola copia. Dopo l'invio la bozza viene cancellata.
 * - Salvataggio automatico ogni 30 secondi se il testo è cambiato; chiudendo
 *   la finestra con modifiche non salvate si chiede se salvarle.
 * - Allegati: dopo ogni salvataggio diventano gli allegati della bozza
 *   (stesso meccanismo dell'inoltro, forward_original_email = la bozza),
 *   così i salvataggi successivi e l'invio li prendono da lì.
 * - Nel dettaglio di una bozza il pulsante "Modifica bozza" la riapre.
 */

const BozzeEmail = {
    uid: 0,                 // UID della bozza salvata (0 = non ancora salvata)
    account: '',            // account della bozza (bloccato all'apertura del compose)
    ultimoStato: '',        // contenuto all'ultimo salvataggio (o all'apertura)
    salvando: null,         // Promise del salvataggio in corso
    INTERVALLO: 30000,

    init() {
        setInterval(() => {
            if (this.aperta() && this.modificata() && !this.vuota()) this.salva(true);
        }, this.INTERVALLO);
        // "Modifica bozza" visibile solo aprendo un'email della cartella Bozze
        document.getElementById('emailList')?.addEventListener('click', (e) => {
            const riga = e.target.closest('.email-item[data-file]');
            if (riga && !e.ctrlKey && !e.metaKey && !e.target.closest('.sel-casella, .stella')) {
                this.mostraPulsante((riga.getAttribute('data-folder') || currentFolder) === 'draft');
            }
        }, true);
    },

    mostraPulsante(si) {
        const btn = document.querySelector('.bozza-btn');
        if (btn) btn.style.display = si ? 'flex' : 'none';
    },

    aperta() {
        const m = document.getElementById('composeModal');
        return !!m && m.style.display !== 'none';
    },

    corpo() {
        const ed = document.getElementById('emailEditor');
        return ed ? ed.innerHTML.trim() : (document.getElementById('composeBody')?.value || '').trim();
    },

    stato() {
        const v = id => (document.getElementById(id)?.value || '').trim();
        return JSON.stringify([v('composeTo'), v('composeCc'), v('composeBcc'), v('composeSubject'), this.corpo(),
            v('composeIsHtml'), selectedFiles.map(f => f.name + '/' + f.size),
            forwardedAttachmentsList.map(a => a.index)]);
    },

    modificata() {
        return this.stato() !== this.ultimoStato;
    },

    vuota() {
        const v = id => (document.getElementById(id)?.value || '').trim();
        const testo = (document.getElementById('emailEditor')?.textContent || v('composeBody')).trim();
        return !v('composeTo') && !v('composeCc') && !v('composeBcc') && !v('composeSubject') && !testo
            && !selectedFiles.length && !forwardedAttachmentsList.length;
    },

    /** Fine di openComposeModal: nuova finestra, il contenuto iniziale non è una modifica. */
    inizia() {
        this.uid = 0;
        this.account = document.getElementById('composeAccountId')?.value || '';
        document.getElementById('composeBozzaUid').value = '';
        this.scriviStato('');
        setTimeout(() => { this.ultimoStato = this.stato(); }, 350);
    },

    scriviStato(testo) {
        const el = document.getElementById('statoBozza');
        if (el) el.textContent = testo;
    },

    /** Salva (o aggiorna) la bozza. @return Promise<bool> */
    salva(automatico = false) {
        if (this.salvando) return this.salvando;
        if (this.vuota()) {
            if (!automatico) showToast('L\'email è vuota: niente da salvare', 'info');
            return Promise.resolve(false);
        }
        if (typeof updateHiddenTextarea === 'function') updateHiddenTextarea();
        const form = document.getElementById('composeForm');
        const dati = new FormData(form);
        selectedFiles.forEach(f => dati.append('attachments[]', f));
        const fwd = document.getElementById('forwardOriginalEmail');
        if (fwd && fwd.value.trim() !== '') {
            dati.set('forward_attachment_indices', forwardedAttachmentsList.map(a => a.index).join(','));
        }
        const ed = document.getElementById('emailEditor');
        if (ed && !(dati.get('body') || '').trim()) dati.set('body', ed.innerHTML.trim());
        dati.set('bozza', '1');
        dati.set('bozza_uid', String(this.uid || ''));
        const statoInviato = this.stato();
        const allegatiInviati = [...selectedFiles.map(f => ({ filename: f.name, size: f.size })), ...forwardedAttachmentsList];
        const btn = document.querySelector('.btn-salva-bozza');
        if (btn) btn.disabled = true;
        this.scriviStato('Salvataggio…');

        this.salvando = fetch('send_email.php', { method: 'POST', body: dati })
            .then(r => r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); }))
            .then(data => {
                if (!data.success) throw new Error(data.error || 'Bozza non salvata');
                const vecchio = this.uid;
                // Modificata durante il salvataggio? (prima di ribasare gli allegati)
                const invariata = this.stato() === statoInviato;
                this.uid = data.bozza_uid || 0;
                document.getElementById('composeBozzaUid').value = this.uid || '';
                // Gli allegati ora sono quelli della bozza: si riprendono da lì
                if (this.uid && fwd) {
                    fwd.value = `${this.account}:draft:${this.uid}`;
                    forwardedAttachmentsList = allegatiInviati.map((a, i) => ({ ...a, index: i, badge: 'allegato' }));
                    selectedFiles = [];
                    if (typeof updateAttachmentPreview === 'function') updateAttachmentPreview();
                } else if (!this.uid && vecchio) {
                    // Il server non ha comunicato l'UID: la prossima volta si salva una bozza nuova
                    this.uid = 0;
                }
                this.ultimoStato = invariata ? this.stato() : statoInviato;
                const ora = new Date().toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
                this.scriviStato(`Bozza salvata alle ${ora}`);
                if (!automatico) showToast('Bozza salvata nella cartella Bozze', 'success');
                this.aggiornaElenco();
                return true;
            })
            .catch(err => {
                this.scriviStato('Bozza non salvata');
                showToast(err.message, 'error');
                return false;
            })
            .finally(() => {
                this.salvando = null;
                if (btn) btn.disabled = false;
            });
        return this.salvando;
    },

    aggiornaElenco() {
        if (currentFolder === 'draft' && !(typeof RicercaEmail !== 'undefined' && RicercaEmail.attiva)) {
            loadEmails(currentPage);
        }
    },

    /**
     * Da closeComposeModal: true = si può chiudere subito. Con modifiche non
     * salvate chiede; scegliendo di salvare, chiude dopo il salvataggio.
     */
    puoChiudere() {
        if (this.chiusuraConfermata) {
            this.chiusuraConfermata = false;
            return true;
        }
        if (!this.aperta() || !this.modificata() || this.vuota()) return true;
        if (!confirm('Salvare questa email nelle Bozze prima di chiudere?\n\nOK = salva nelle Bozze\nAnnulla = chiudi senza salvare')) {
            return true;
        }
        this.salva().then(ok => {
            if (ok) {
                this.chiusuraConfermata = true;
                closeComposeModal();
            }
        });
        return false;
    },

    /** Dopo un invio riuscito (sendEmail): il server ha già cancellato la bozza. */
    dopoInvio() {
        const cera = !!this.uid;
        this.uid = 0;
        this.ultimoStato = '';
        this.scriviStato('');
        if (cera) this.aggiornaElenco();
    },

    /** "Modifica bozza": riapre nel compose la bozza aperta nel dettaglio. */
    async apri() {
        if (!currentUser || !currentEmailFile) {
            showToast('Nessuna bozza selezionata', 'error');
            return;
        }
        const uid = String(currentEmailFile);
        const s = document.getElementById('userSelect');
        const account = (s && s.value.trim()) ? s.value : currentUser;
        try {
            const r = await fetch('get_email_detail.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ user: account, folder: 'draft', file: uid }).toString()
            });
            const d = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
            if (d.error) throw new Error(d.error);

            openComposeModal('new');
            document.getElementById('composeModalTitle').innerHTML = '<i class="fas fa-file-alt"></i> Bozza';
            const imposta = (id, v) => { const el = document.getElementById(id); if (el) el.value = v || ''; };
            imposta('composeTo', d.to);
            imposta('composeCc', d.cc);
            imposta('composeBcc', d.bcc);
            imposta('composeSubject', d.subject);

            const ed = document.getElementById('emailEditor');
            const html = (d.html_body || '').trim();
            if (html && ed) {
                ed.innerHTML = html
                    .replace(/<meta\b[^>]*>/gi, '')
                    .replace(/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/gi, '')
                    .replace(/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/gi, '')
                    .replace(/<base\b[^>]*>/gi, '')
                    .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '');
                toggleFormat('html');
            } else if (ed) {
                ed.innerHTML = escapeHtml(d.text_body || '').replace(/\n/g, '<br>') || '<div><br></div>';
                toggleFormat('text');
            }
            if (typeof updateHiddenTextarea === 'function') updateHiddenTextarea();

            // Gli allegati della bozza, ripresi dal server all'invio o al prossimo salvataggio
            document.getElementById('forwardOriginalEmail').value = `${account}:draft:${uid}`;
            forwardedAttachmentsList = (d.attachments || []).map((a, i) => ({
                index: i, filename: a.filename || `allegato_${i}`, size: a.size || 0,
                content_type: a.content_type || 'application/octet-stream', badge: 'allegato',
            }));
            if (typeof updateAttachmentPreview === 'function') updateAttachmentPreview();

            this.uid = parseInt(uid, 10) || 0;
            this.account = account;
            document.getElementById('composeBozzaUid').value = String(this.uid);
            setTimeout(() => { this.ultimoStato = this.stato(); }, 350);
            this.scriviStato('Bozza aperta');
        } catch (err) {
            showToast('Bozza non aperta: ' + err.message, 'error');
        }
    },
};

document.addEventListener('DOMContentLoaded', () => BozzeEmail.init());
