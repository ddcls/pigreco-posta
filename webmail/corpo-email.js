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
 * Corpo dell'email dentro lo spazio che c'è: il riquadro di lettura (iframe
 * #detailBodyHtml) e il foglio di stampa. Tabelle a larghezza fissa, righe che
 * non vanno a capo e indirizzi lunghissimi uscivano a destra.
 *
 * CorpoEmail.adatta() prova in ordine, e si ferma al primo modo che basta:
 *  1. l'email com'è, al massimo rimpicciolita fino al 75 %;
 *  2. larghezze fisse tolte e righe "nowrap" mandate a capo, fino al 50 %;
 *  3. anche le parole lunghe spezzate, fino al 30 %.
 * Se non basta (elementi messi lontanissimo apposta) niente zoom: si scorre.
 */
const CorpoEmail = {

    /** Larghezza utile di un A4 verticale con i margini di @page (190 mm) */
    LARGHEZZA_FOGLIO: 718,

    PASSI: [['', .75], ['adatta', .5], ['adatta spezza', .3]],

    /** Regole per il corpo; `c` è il selettore del contenuto (body nell'iframe, .corpo nella stampa) */
    css(c) {
        return `
            ${c}{box-sizing:border-box!important;min-width:0!important;max-width:100%!important;overflow-wrap:break-word;}
            ${c} img,${c} video{max-width:100%!important;}
            ${c} pre{white-space:pre-wrap!important;overflow-wrap:anywhere!important;}
            ${c}[data-pg~="adatta"] *{min-width:0!important;max-width:100%!important;}
            ${c}[data-pg~="adatta"] td,${c}[data-pg~="adatta"] th,${c}[data-pg~="adatta"] col{width:auto!important;}
            ${c}[data-pg~="adatta"] [data-pg-largo="tabella"]{width:100%!important;}
            ${c}[data-pg~="adatta"] [data-pg-largo="blocco"]{width:auto!important;}
            ${c}[data-pg~="adatta"] a{overflow-wrap:anywhere!important;}
            ${c}[data-pg~="adatta"] [data-pg-testo="normal"]{white-space:normal!important;}
            ${c}[data-pg~="adatta"] [data-pg-testo="pre-wrap"]{white-space:pre-wrap!important;}
            ${c}[data-pg~="spezza"] *{overflow-wrap:anywhere!important;}`;
    },

    /**
     * Per il passo "adatta" (css): segna gli elementi più larghi dello spazio (dentro una
     * cella max-width:100% non basta a stringerli) e quelli che non vanno a capo.
     */
    segna(contenuto, largo) {
        const vista = contenuto.ownerDocument.defaultView;
        contenuto.querySelectorAll('*').forEach(el => {
            if (el.dataset === undefined || /^(IMG|VIDEO|STYLE|BR)$/.test(el.tagName)) return;
            if (el.getBoundingClientRect().width > largo) {
                el.setAttribute('data-pg-largo', el.tagName === 'TABLE' ? 'tabella' : 'blocco');
            }
            if (el.hasAttribute('data-pg-testo')) return;
            const spazi = vista.getComputedStyle(el).whiteSpace;
            if (spazi === 'nowrap') el.setAttribute('data-pg-testo', 'normal');
            else if (spazi === 'pre') el.setAttribute('data-pg-testo', 'pre-wrap');
        });
    },

    /** Fa stare `contenuto` nella larghezza di `contenitore`; restituisce lo zoom usato */
    adatta(contenitore, contenuto) {
        const zoom = z => z === 1 ? contenuto.style.removeProperty('zoom')
            : contenuto.style.setProperty('zoom', String(Math.floor(z * 1000) / 1000), 'important');
        const largo = contenitore.clientWidth;
        for (const [passo, minimo] of this.PASSI) {
            zoom(1);
            if (passo === 'adatta') this.segna(contenuto, largo);
            contenuto.setAttribute('data-pg', passo);
            let z = 1;
            for (let giro = 0; giro < 4; giro++) {
                const serve = contenitore.scrollWidth;
                if (serve <= largo + 1) return z;
                z *= largo / serve * (giro ? .99 : 1);
                if (z < minimo) break;
                zoom(z);
            }
        }
        zoom(1);
        return 1;
    },

    /** Lettura: adatta il corpo alla larghezza dell'iframe e l'iframe all'altezza del corpo */
    adattaIframe(iframe) {
        const doc = iframe.contentDocument;
        // scheda HTML nascosta: si rifà quando torna visibile (osserva)
        if (!doc || !doc.body || !iframe.clientWidth) return;
        const radice = doc.documentElement;
        // senza barra verticale durante la misura: toglierebbe larghezza
        radice.style.overflowY = 'hidden';
        this.adatta(radice, doc.body);
        // Prima all'altezza minima: scrollHeight non è mai minore dell'altezza attuale
        // dell'iframe, quindi dopo un'email lunga quelle corte restavano altissime.
        iframe.style.height = '150px';
        const corpo = doc.body.style.zoom ? 0 : doc.body.scrollHeight;
        const barra = radice.scrollWidth > radice.clientWidth + 1 ? 18 : 0;
        iframe.style.height = Math.max(corpo, radice.scrollHeight, 150) + barra + 'px';
        radice.style.overflowY = '';
        iframe.dataset.larghezza = iframe.clientWidth;
    },

    /** Riadatta quando cambia la larghezza del riquadro (finestra, colonna dell'elenco, scheda HTML) */
    osserva(iframe) {
        if (iframe.dataset.osservato || typeof ResizeObserver === 'undefined') return;
        iframe.dataset.osservato = '1';
        let attesa = 0;
        new ResizeObserver(() => {
            if (!iframe.clientWidth || String(iframe.clientWidth) === iframe.dataset.larghezza) return;
            cancelAnimationFrame(attesa);
            attesa = requestAnimationFrame(() => {
                try { this.adattaIframe(iframe); } catch (e) { /* documento in caricamento */ }
            });
        }).observe(iframe);
    },

    // ---------------------------------------------------------------- stampa

    /**
     * HTML non fidato per la finestra di stampa: DOMPurify toglie script, on*, moduli
     * e <base>; restano gli stili dell'email. La finestra ha anche una CSP senza script.
     */
    pulisciPerStampa(html) {
        const pulito = DOMPurify.sanitize(html, {
            WHOLE_DOCUMENT: true,
            FORBID_TAGS: ['base', 'link', 'meta', 'title', 'form', 'input', 'button', 'textarea', 'select', 'option',
                'iframe', 'audio', 'video'],
            FORBID_ATTR: ['action', 'formaction']
        });
        return new DOMParser().parseFromString(pulito, 'text/html');
    },

    /** Riempie la finestra di stampa: email = {oggetto, da, a, data, html, testo} */
    stampa(finestra, email) {
        const doc = finestra.document;
        const L = this.LARGHEZZA_FOGLIO;
        doc.open();
        doc.write(`<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8">
            <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src http: https: data: blob:;
                style-src 'unsafe-inline'; font-src data:; form-action 'none'; base-uri 'none'">
            <meta name="referrer" content="no-referrer"><title>Stampa Email</title></head><body></body></html>`);
        doc.close();

        // Un elemento con il suo stile, che gli stili dell'email non cambiano
        const crea = (tag, stile, testo) => {
            const el = doc.createElement(tag);
            el.style.cssText = stile.replace(/;/g, ' !important;');
            if (testo !== undefined) el.textContent = testo;
            return el;
        };
        const carattere = 'font-family:Arial,Helvetica,sans-serif;color:#333;';

        const testata = crea('div', `${carattere}border-bottom:2px solid #3b82f6;padding:0 0 14px;margin:0 0 18px;`);
        testata.append(crea('div', `${carattere}color:#3b82f6;font-size:22px;font-weight:bold;line-height:1.25;`
            + 'margin:0 0 10px;overflow-wrap:anywhere;', email.oggetto));
        for (const [nome, valore] of [['Da:', email.da], ['A:', email.a], ['Data:', email.data]]) {
            const riga = crea('div', `${carattere}display:flex;font-size:14px;line-height:1.4;margin:0 0 4px;`);
            riga.append(crea('b', `${carattere}flex:0 0 50px;color:#666;`, nome),
                crea('span', `${carattere}flex:1;min-width:0;overflow-wrap:anywhere;`, valore));
            testata.append(riga);
        }

        const foglio = doc.createElement('div');
        foglio.className = 'foglio';
        const corpo = doc.createElement('div');
        corpo.className = 'corpo';
        if (email.html && typeof DOMPurify !== 'undefined') {
            const pulito = this.pulisciPerStampa(email.html);
            corpo.style.cssText = pulito.body.getAttribute('style') || '';
            for (const s of pulito.querySelectorAll('head style')) corpo.append(doc.importNode(s, true));
            for (const n of pulito.body.childNodes) corpo.append(doc.importNode(n, true));
        } else {
            const testo = crea('div', 'white-space:pre-wrap;overflow-wrap:anywhere;');
            testo.textContent = email.testo || 'Nessun contenuto disponibile';
            corpo.append(testo);
        }
        corpo.querySelectorAll('a[href], area[href]').forEach(a => {
            a.target = '_blank';
            a.rel = 'noopener noreferrer';
        });
        corpo.querySelectorAll('[loading]').forEach(el => el.removeAttribute('loading'));
        foglio.append(corpo);

        const piede = crea('div', `${carattere}margin:30px 0 0;padding:10px 0 0;border-top:1px solid #ddd;font-size:12px;`
            + 'color:#999;', 'Email stampata il ' + new Date().toLocaleString('it-IT'));
        const barra = crea('div', 'text-align:right;margin:0 0 16px;');
        barra.className = 'barra-stampa';
        const bottone = (testo, colore, azione) => {
            const b = crea('button', `${carattere}padding:10px 20px;background:${colore};color:#fff;border:0;`
                + 'border-radius:5px;cursor:pointer;margin:0 0 0 10px;font-size:14px;', testo);
            b.type = 'button';
            b.addEventListener('click', azione);
            barra.append(b);
        };
        const adatta = () => this.adatta(foglio, corpo);
        bottone('🖨️ Stampa', '#3b82f6', () => { adatta(); finestra.print(); });
        bottone('✕ Chiudi', '#6b7280', () => finestra.close());

        // Per ultimo: vale sugli stili dell'email. page: le email di Word hanno pagine
        // con nome (WordSection1), che manderebbero il corpo in un foglio nuovo.
        const stile = doc.createElement('style');
        stile.textContent = `
            @page{size:auto!important;margin:12mm 10mm!important;}
            html,body{height:auto!important;min-height:0!important;width:auto!important;min-width:0!important;
                max-width:none!important;overflow:visible!important;position:static!important;background:#fff!important;}
            body{display:block!important;margin:20px auto!important;padding:0!important;width:${L}px!important;}
            .foglio{width:${L}px!important;max-width:none!important;margin:0!important;padding:0!important;}
            .corpo{margin:0!important;padding:0!important;font-family:Arial,Helvetica,sans-serif;font-size:14px;
                line-height:1.6;color:#000;}
            .corpo img{height:auto;break-inside:avoid;}
            .corpo tr{break-inside:avoid;}
            .corpo, .corpo *{page:auto!important;}
            ${this.css('.corpo')}
            @media print{
                body{margin:0!important;}
                .barra-stampa{display:none!important;}
            }`;
        doc.body.append(barra, testata, foglio, piede, stile);

        // Riquadri dell'email con la barra di scorrimento: sul foglio si vede tutto
        corpo.querySelectorAll('*').forEach(el => {
            const s = finestra.getComputedStyle(el);
            if (/auto|scroll/.test(s.overflowX + s.overflowY)) {
                el.style.setProperty('overflow', 'visible', 'important');
                el.style.setProperty('max-height', 'none', 'important');
                el.style.setProperty('height', 'auto', 'important');
            }
        });
        // Elementi messi fuori a destra apposta (testo nascosto): farebbero rimpicciolire tutto il foglio
        const bordo = foglio.getBoundingClientRect().right;
        corpo.querySelectorAll('*').forEach(el => {
            if (el.getBoundingClientRect().left > bordo + L
                && /absolute|fixed/.test(finestra.getComputedStyle(el).position)) {
                el.style.setProperty('display', 'none', 'important');
            }
        });
        adatta();
        for (const img of corpo.querySelectorAll('img')) {
            if (!img.complete) img.addEventListener('load', adatta);
        }
        finestra.addEventListener('beforeprint', adatta);
    },
};
