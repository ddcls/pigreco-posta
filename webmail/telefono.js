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
 * Webmail sul telefono (e sui tablet, dove il menu delle cartelle è chiuso):
 * - barra "casella corrente" in cima all'elenco (#accountCorrente): mostra
 *   l'account e la cartella aperti, toccandola si apre il pannello cartelle
 *   (toggleCartelle) per cambiarli;
 * - pulsante tondo ＋ (#fabNuovaEmail) in basso a destra = composeEmail().
 * Si aggiorna da solo osservando #userSelect e #emailList, senza toccare il
 * codice di index.php. Visibile solo sotto i 960px (vedi telefono.css).
 */
const PostaTelefono = {
    init() {
        const sidebar = document.getElementById('sidebar');
        const select = document.getElementById('userSelect');
        if (!sidebar || !select) return;

        const barra = document.createElement('button');
        barra.type = 'button';
        barra.id = 'accountCorrente';
        barra.className = 'account-corrente';
        barra.title = 'Cambia account o cartella';
        barra.innerHTML = '<i class="fas fa-user-circle"></i>'
            + '<span class="account-testi"><span class="account-nome"></span><span class="account-cartella"></span></span>'
            + '<i class="fas fa-chevron-down"></i>';
        barra.addEventListener('click', (e) => toggleCartelle(e));
        sidebar.insertBefore(barra, sidebar.firstChild);

        const piu = document.createElement('button');
        piu.type = 'button';
        piu.id = 'fabNuovaEmail';
        piu.className = 'fab-nuova-email';
        piu.title = 'Nuova email';
        piu.setAttribute('aria-label', 'Nuova email');
        piu.innerHTML = '<i class="fas fa-plus"></i>';
        piu.addEventListener('click', () => composeEmail());
        document.body.appendChild(piu);

        const aggiorna = () => this.aggiorna();
        select.addEventListener('change', aggiorna);
        new MutationObserver(aggiorna).observe(select, { childList: true });
        const lista = document.getElementById('emailList');
        if (lista) new MutationObserver(aggiorna).observe(lista, { childList: true });
        aggiorna();
    },

    aggiorna() {
        const select = document.getElementById('userSelect');
        const barra = document.getElementById('accountCorrente');
        if (!select || !barra) return;
        const scelta = select.value ? select.options[select.selectedIndex] : null;
        barra.querySelector('.account-nome').textContent = scelta ? scelta.textContent.trim() : 'Scegli un account';
        const cartella = document.querySelector('.folder-btn.active');
        const nomeCartella = cartella ? (cartella.querySelector('span')?.textContent || cartella.title || '').trim() : '';
        barra.querySelector('.account-cartella').textContent = scelta && nomeCartella ? nomeCartella : '';
    },
};

document.addEventListener('DOMContentLoaded', () => PostaTelefono.init());
