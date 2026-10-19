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
 * Cartelle dell'account: crea, rinomina, elimina (pannello a sinistra).
 *
 * - "＋ Nuova cartella" sotto le cartelle: nome e cartella in cui crearla.
 * - Accanto a ogni cartella creata sul server (#altreCartelle, disegnate da
 *   loadAltreCartelle in index.php) il pulsante ⋯ apre il menu
 *   Nuova sottocartella / Rinomina / Elimina. Le cinque cartelle speciali
 *   non si modificano.
 * - Caselle copiate in locale (data-solo-locale): le cartelle valgono solo
 *   per la copia sul server e quelle che vengono dal fornitore non si
 *   rinominano né si eliminano (data-modificabile vuoto), vedi
 *   MailboxService::folderEditable().
 *
 * Le richieste vanno a cartelle.php.
 */

const CartelleEmail = {
    menu: null,

    init() {
        const contenitore = document.getElementById('altreCartelle');
        if (!contenitore) return;

        const nuova = document.createElement('button');
        nuova.type = 'button';
        nuova.className = 'btn-nuova-cartella';
        nuova.title = 'Crea una nuova cartella';
        nuova.innerHTML = '<i class="fas fa-folder-plus"></i><span>Nuova cartella</span>';
        nuova.addEventListener('click', () => this.chiediNome('crea', ''));
        contenitore.after(nuova);

        new MutationObserver(() => this.aggiungiMenu()).observe(contenitore, { childList: true });
        document.addEventListener('click', (e) => {
            if (this.menu && !e.target.closest('.cartella-menu, .cartella-menu-btn')) this.chiudiMenu();
        });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.chiudiMenu(); });
    },

    account() {
        const s = document.getElementById('userSelect');
        return (s && s.value.trim()) ? s.value : currentUser;
    },

    soloLocale() {
        return document.getElementById('altreCartelle')?.dataset.soloLocale === '1';
    },

    /** Ogni cartella in una riga con il suo pulsante ⋯ (un button non può contenerne un altro). */
    aggiungiMenu() {
        const contenitore = document.getElementById('altreCartelle');
        contenitore.querySelectorAll(':scope > .folder-btn, :scope > .cartella-gruppo').forEach(el => {
            const riga = document.createElement('div');
            riga.className = 'cartella-riga';
            el.replaceWith(riga);
            riga.appendChild(el);
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cartella-menu-btn';
            btn.title = 'Azioni sulla cartella';
            btn.setAttribute('aria-haspopup', 'menu');
            btn.innerHTML = '<i class="fas fa-ellipsis-h"></i>';
            btn.addEventListener('click', (e) => { e.stopPropagation(); this.apriMenu(btn, el); });
            riga.appendChild(btn);
        });
    },

    chiudiMenu() {
        this.menu?.remove();
        this.menu = null;
        document.querySelector('.cartella-menu-btn.aperto')?.classList.remove('aperto');
    },

    apriMenu(btn, el) {
        const giaAperto = btn.classList.contains('aperto');
        this.chiudiMenu();
        if (giaAperto) return;
        const id = el.getAttribute('data-folder');
        const nome = el.querySelector('span')?.textContent || '';
        const modificabile = el.getAttribute('data-modificabile') === '1';
        const menu = document.createElement('div');
        menu.className = 'cartella-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = `
            <button type="button" role="menuitem" data-azione="sotto"><i class="fas fa-folder-plus"></i> Nuova sottocartella</button>
            ${modificabile ? `
            <button type="button" role="menuitem" data-azione="rinomina"><i class="fas fa-pen"></i> Rinomina</button>
            <button type="button" role="menuitem" data-azione="elimina" class="pericolo"><i class="fas fa-trash-alt"></i> Elimina</button>`
            : `<p class="cartella-menu-nota">Viene dal fornitore di posta: si rinomina o si elimina presso il fornitore.</p>`}`;
        menu.addEventListener('click', (e) => {
            const voce = e.target.closest('[data-azione]');
            if (!voce) return;
            this.chiudiMenu();
            const azione = voce.getAttribute('data-azione');
            if (azione === 'sotto') this.chiediNome('crea', id);
            else if (azione === 'rinomina') this.chiediNome('rinomina', id, nome);
            else this.elimina(id, nome);
        });
        document.body.appendChild(menu);
        const r = btn.getBoundingClientRect();
        // A destra del pulsante (sopra l'elenco), così non copre le altre cartelle
        const destra = r.right + 4 + menu.offsetWidth <= window.innerWidth - 8;
        menu.style.top = `${Math.max(8, Math.min(r.top, window.innerHeight - menu.offsetHeight - 8))}px`;
        menu.style.left = `${destra ? r.right + 4 : Math.max(8, r.left - menu.offsetWidth - 4)}px`;
        btn.classList.add('aperto');
        this.menu = menu;
        menu.querySelector('button')?.focus();
    },

    /** Finestra con il nome: azione "crea" (dentro genitore) o "rinomina" (id). */
    chiediNome(azione, id, nomeAttuale = '') {
        if (!this.account()) {
            showToast('Scegli prima un account', 'error');
            return;
        }
        document.querySelector('.cartella-overlay')?.remove();
        const crea = azione === 'crea';
        const opzioni = ['<option value="">— Primo livello —</option>']
            .concat(Object.entries(typeof altreCartelle === 'object' ? altreCartelle : {})
                .map(([cid, percorso]) => `<option value="${escapeHtmlAttr(cid)}"${cid === id ? ' selected' : ''}>${escapeHtml(percorso)}</option>`))
            .join('');
        const overlay = document.createElement('div');
        overlay.className = 'compose-modal-overlay cartella-overlay';
        overlay.innerHTML = `
            <div class="compose-modal cartella-modal" role="dialog" aria-modal="true" aria-labelledby="titoloCartella">
                <div class="compose-modal-header">
                    <h3 id="titoloCartella"><i class="fas ${crea ? 'fa-folder-plus' : 'fa-pen'}"></i> ${crea ? 'Nuova cartella' : 'Rinomina la cartella'}</h3>
                    <button class="btn-close-compose" type="button" title="Chiudi"><i class="fas fa-times"></i></button>
                </div>
                <form class="cartella-corpo">
                    <label for="cartellaNome">Nome</label>
                    <input type="text" id="cartellaNome" maxlength="80" required autocomplete="off" value="${escapeHtmlAttr(nomeAttuale)}">
                    ${crea ? `
                    <label for="cartellaGenitore">Dentro a</label>
                    <select id="cartellaGenitore">${opzioni}</select>` : ''}
                    <p class="cartella-nota">Il nome non può contenere / . \\ % * "</p>
                    ${this.soloLocale() ? `<p class="cartella-nota avviso"><i class="fas fa-info-circle"></i>
                        Questa casella è una copia sul server (copia locale): la cartella esisterà solo qui,
                        non presso il fornitore di posta.</p>` : ''}
                    <div class="cartella-azioni">
                        <button type="button" class="archivia-btn-secondario" data-azione="annulla">Annulla</button>
                        <button type="submit" class="archivia-btn-primario"><i class="fas fa-check"></i> ${crea ? 'Crea' : 'Rinomina'}</button>
                    </div>
                </form>
            </div>`;
        const chiudi = () => overlay.remove();
        overlay.querySelector('.btn-close-compose').addEventListener('click', chiudi);
        overlay.querySelector('[data-azione="annulla"]').addEventListener('click', chiudi);
        overlay.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); chiudi(); } });
        overlay.querySelector('form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const nome = overlay.querySelector('#cartellaNome').value;
            const invia = overlay.querySelector('[type="submit"]');
            invia.disabled = true;
            const ok = crea
                ? await this.richiesta({ azione: 'crea', nome, genitore: overlay.querySelector('#cartellaGenitore').value })
                : await this.richiesta({ azione: 'rinomina', nome, cartella: id });
            if (ok) chiudi(); else invia.disabled = false;
        });
        document.body.appendChild(overlay);
        const campo = overlay.querySelector('#cartellaNome');
        campo.focus();
        campo.select();
    },

    elimina(id, nome) {
        if (!confirm(`Eliminare la cartella "${nome}"?\n\nSi può eliminare solo se è vuota e non contiene altre cartelle.`)) return;
        this.richiesta({ azione: 'elimina', cartella: id });
    },

    /** Invia a cartelle.php e aggiorna il pannello. @return bool riuscita */
    async richiesta(dati) {
        const account = this.account();
        try {
            const r = await fetch('cartelle.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ user: account, ...dati }).toString()
            });
            const data = await r.json().catch(() => { throw new Error('Errore HTTP ' + r.status); });
            if (!data.success) throw new Error(data.error || 'Operazione non riuscita');
            showToast(data.message, 'success');

            // La cartella aperta è stata rinominata o eliminata
            if (dati.cartella && dati.cartella === currentFolder) {
                switchFolder(dati.azione === 'rinomina' && data.id ? data.id : 'inbox');
            } else if (dati.azione === 'rinomina' && dati.cartella && String(currentFolder).startsWith(dati.cartella)
                && ['.', '/'].includes(String(currentFolder).charAt(dati.cartella.length))) {
                switchFolder('inbox');   // era aperta una sua sottocartella
            }
            loadAltreCartelle(account);
            return true;
        } catch (err) {
            showToast('Errore: ' + err.message, 'error');
            return false;
        }
    },
};

document.addEventListener('DOMContentLoaded', () => CartelleEmail.init());
