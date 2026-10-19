/**
 * Finestra "Aspetto": scelta del colore dell'interfaccia.
 *
 * Il colore attivo arriva dal server come attributo data-tema su <html>
 * (nessun attributo = tema di base "marrone", vedi temi.css e lib/tema.php).
 * Cliccando un colore la pagina cambia subito (anteprima); "Salva" lo
 * memorizza per l'utente (salva_tema.php), "Annulla" torna a quello di prima.
 */

const TemaWebmail = {
    // Colori per le anteprime nella finestra (gli stessi di temi.css)
    anteprime: {
        marrone:   { accento: '#8B4513', pannello: '#f5f0eb' },
        blu:       { accento: '#1f5fa8', pannello: '#eef3f9' },
        arancione: { accento: '#c25410', pannello: '#fbf1e9' },
        verde:     { accento: '#2e7d4f', pannello: '#eef6f1' },
        bordeaux:  { accento: '#8e2440', pannello: '#f8eef1' },
        grafite:   { accento: '#3d4852', pannello: '#f1f3f5' },
    },
    salvato: null,
    scelto: null,

    attuale() {
        return document.documentElement.getAttribute('data-tema') || 'marrone';
    },

    applica(tema) {
        if (tema === 'marrone') {
            document.documentElement.removeAttribute('data-tema');
        } else {
            document.documentElement.setAttribute('data-tema', tema);
        }
        document.querySelectorAll('#aspettoTemi .tema-scelta').forEach(b => {
            const scelto = b.dataset.tema === tema;
            b.classList.toggle('scelto', scelto);
            b.setAttribute('aria-pressed', scelto ? 'true' : 'false');
        });
    },

    costruisciScelte() {
        const contenitore = document.getElementById('aspettoTemi');
        if (!contenitore || contenitore.childElementCount > 0) return;
        Object.entries(TEMI_WEBMAIL).forEach(([id, nome]) => {
            const c = this.anteprime[id] || this.anteprime.marrone;
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'tema-scelta';
            b.dataset.tema = id;
            b.innerHTML = `
                <span class="tema-anteprima" aria-hidden="true">
                    <span class="tema-anteprima-lato" style="background:${c.pannello}">
                        <span class="tema-anteprima-barra" style="background:${c.accento}"></span>
                    </span>
                    <span class="tema-anteprima-corpo">
                        <span class="tema-anteprima-barra" style="background:${c.accento}"></span>
                        <span class="tema-anteprima-riga" style="background:${c.pannello}"></span>
                        <span class="tema-anteprima-riga corta" style="background:${c.accento}"></span>
                    </span>
                </span>
                <span class="tema-nome"></span>`;
            b.querySelector('.tema-nome').innerHTML = `${escapeHtml(nome)}<i class="fas fa-check-circle"></i>`;
            b.addEventListener('click', () => {
                this.scelto = id;
                this.applica(id);
            });
            contenitore.appendChild(b);
        });
    },

    apri() {
        // Chiudi il menu utente, se aperto
        document.getElementById('settingsMenu')?.classList.remove('show');
        this.costruisciScelte();
        this.salvato = this.attuale();
        this.scelto = this.salvato;
        this.applica(this.salvato);
        const predefinito = document.getElementById('aspettoPredefinito');
        if (predefinito) predefinito.checked = false;
        document.getElementById('aspettoModal').style.display = 'flex';
    },

    chiudi() {
        // Chiudere senza salvare annulla l'anteprima
        this.applica(this.salvato || this.attuale());
        document.getElementById('aspettoModal').style.display = 'none';
    },

    async salva() {
        const bottone = document.getElementById('aspettoSalva');
        const predefinito = document.getElementById('aspettoPredefinito');
        const dati = new URLSearchParams({ tema: this.scelto || this.attuale() });
        if (predefinito && predefinito.checked) dati.append('predefinito', '1');
        bottone.disabled = true;
        try {
            const risposta = await fetch('salva_tema.php', { method: 'POST', body: dati });
            const json = await risposta.json().catch(() => ({}));
            if (!risposta.ok || !json.success) {
                throw new Error(json.error || ('Errore HTTP ' + risposta.status));
            }
            this.salvato = json.tema;
            document.getElementById('aspettoModal').style.display = 'none';
            showToast(predefinito && predefinito.checked
                ? 'Colore salvato, anche come predefinito per tutti'
                : 'Colore salvato', 'success');
        } catch (e) {
            showToast('Colore non salvato: ' + e.message, 'error');
        } finally {
            bottone.disabled = false;
        }
    },
};

function apriAspetto() { TemaWebmail.apri(); }
function chiudiAspetto() { TemaWebmail.chiudi(); }
function salvaAspetto() { TemaWebmail.salva(); }
