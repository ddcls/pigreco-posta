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
 * email_editor.js - Editor HTML WYSIWYG per composizione email
 * Compatibile con tutti i client email (Gmail, Outlook, Apple Mail, ecc.)
 * Genera HTML pulito e ben strutturato con <div> per paragrafi
 */

// ==================== VARIABILI GLOBALI ====================
let emailEditor = null;
let editorContent = '';

// ==================== INIZIALIZZAZIONE EDITOR ====================
/**
 * Inizializza l'editor HTML nel composer
 */
function initEmailEditor() {
    // Trova il container dell'editor
    const editorContainer = document.getElementById('emailEditorContainer');
    if (!editorContainer) {
        return;
    }
    
    // Crea struttura HTML dell'editor
    editorContainer.innerHTML = `
        <!-- Toolbar di formattazione -->
        <div id="editorToolbar" class="editor-toolbar">
            <button type="button" class="toolbar-btn" onclick="editorCommand('bold')" title="Grassetto">
                <i class="fas fa-bold"></i>
            </button>
            <button type="button" class="toolbar-btn" onclick="editorCommand('italic')" title="Corsivo">
                <i class="fas fa-italic"></i>
            </button>
            <button type="button" class="toolbar-btn" onclick="editorCommand('underline')" title="Sottolineato">
                <i class="fas fa-underline"></i>
            </button>
            <button type="button" class="toolbar-btn" onclick="editorCommand('strikeThrough')" title="Barrato">
                <i class="fas fa-strikethrough"></i>
            </button>
            <div class="toolbar-separator"></div>
            <button type="button" class="toolbar-btn" onclick="editorCommand('insertUnorderedList')" title="Elenco puntato">
                <i class="fas fa-list-ul"></i>
            </button>
            <button type="button" class="toolbar-btn" onclick="editorCommand('insertOrderedList')" title="Elenco numerato">
                <i class="fas fa-list-ol"></i>
            </button>
            <div class="toolbar-separator"></div>
            <button type="button" class="toolbar-btn" onclick="editorCommand('justifyLeft')" title="Allinea a sinistra">
                <i class="fas fa-align-left"></i>
            </button>
            <button type="button" class="toolbar-btn" onclick="editorCommand('justifyCenter')" title="Centra">
                <i class="fas fa-align-center"></i>
            </button>
            <button type="button" class="toolbar-btn" onclick="editorCommand('justifyRight')" title="Allinea a destra">
                <i class="fas fa-align-right"></i>
            </button>
            <div class="toolbar-separator"></div>
            <button type="button" class="toolbar-btn" onclick="insertLink()" title="Inserisci link">
                <i class="fas fa-link"></i>
            </button>
            <button type="button" class="toolbar-btn" onclick="insertImage()" title="Inserisci immagine">
                <i class="fas fa-image"></i>
            </button>
            <div class="toolbar-separator"></div>
            <button type="button" class="toolbar-btn" onclick="clearFormatting()" title="Cancella formattazione">
                <i class="fas fa-eraser"></i>
            </button>
        </div>
        
        <!-- Editor contenteditable -->
        <div id="emailEditor" class="email-editor" contenteditable="true" placeholder="Scrivi il tuo messaggio qui...">
            <div><br></div>
        </div>
        
        <!-- Hidden textarea per compatibilità con FormData -->
        <textarea id="composeBody" name="body" style="display:none;"></textarea>
    `;
    
    // Inizializza l'editor
    emailEditor = document.getElementById('emailEditor');
    if (emailEditor) {
        // Eventi per aggiornare il textarea nascosto
        emailEditor.addEventListener('input', updateHiddenTextarea);
        emailEditor.addEventListener('keyup', updateHiddenTextarea);
        emailEditor.addEventListener('paste', function(e) {
            setTimeout(updateHiddenTextarea, 10);
        });
        
        // Focus sull'editor
        emailEditor.focus();
    }
}

/**
 * Aggiorna il textarea nascosto con il contenuto HTML dell'editor
 */
function updateHiddenTextarea() {
    if (!emailEditor) return;
    
    const textarea = document.getElementById('composeBody');
    if (!textarea) return;
    
    // Ottieni HTML pulito e compatibile con email
    const cleanHtml = getCleanEmailHtml();
    textarea.value = cleanHtml;
    
    editorContent = cleanHtml;
}

/**
 * Ottieni HTML pulito e compatibile con client email
 */
function getCleanEmailHtml() {
    if (!emailEditor) return '';
    
    // Clona l'editor per pulizia
    const clone = emailEditor.cloneNode(true);
    
    // Pulisci HTML per compatibilità email
    const cleanHtml = cleanHtmlForEmail(clone.innerHTML);
    return cleanHtml;
}

/**
 * Pulisci HTML per compatibilità con client email
 */
function cleanHtmlForEmail(html) {
    // Rimuovi spazi bianchi multipli
    html = html.replace(/\s+/g, ' ');
    html = html.replace(/>\s+</g, '><');
    
    // Converti <p> in <div> (meglio supportato da client email)
    html = html.replace(/<p>/g, '<div>');
    html = html.replace(/<\/p>/g, '</div>');
    
    // Rimuovi classi e stili inline non necessari
    html = html.replace(/class="[^"]*"/g, '');
    html = html.replace(/style="[^"]*"/g, '');
    
    // Aggiungi container principale con stili inline (per compatibilità email)
    html = `
    <div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #333;">
        ${html}
    </div>
    `;
    
    // Rimuovi div vuoti
    html = html.replace(/<div><br><\/div>/g, '');
    html = html.replace(/<div>\s*<\/div>/g, '');
    
    // Aggiungi <br> per compatibilità
    html = html.replace(/\n/g, '<br>');
    
    return html.trim();
}

// ==================== COMANDI EDITOR ====================
/**
 * Esegui comando di formattazione sull'editor
 */
function editorCommand(command, value = null) {
    if (!emailEditor) return;
    
    document.execCommand(command, false, value);
    emailEditor.focus();
    updateHiddenTextarea();
}

/**
 * Inserisci link
 */
function insertLink() {
    if (!emailEditor) return;
    
    const url = prompt('Inserisci URL:');
    if (url) {
        const text = prompt('Testo del link (lascia vuoto per usare l\'URL):', url);
        const linkText = text || url;
        
        const linkHtml = `<a href="${url}" style="color: #0066cc; text-decoration: underline;">${linkText}</a>`;
        document.execCommand('insertHTML', false, linkHtml);
        emailEditor.focus();
        updateHiddenTextarea();
    }
}

/**
 * Inserisci immagine
 */
function insertImage() {
    if (!emailEditor) return;
    
    const url = prompt('Inserisci URL dell\'immagine:');
    if (url) {
        const alt = prompt('Testo alternativo (alt):', 'Immagine');
        const width = prompt('Larghezza (px, lascia vuoto per auto):', '');
        
        let imgHtml = `<img src="${url}" alt="${alt}"`;
        if (width) imgHtml += ` width="${width}"`;
        imgHtml += ` style="max-width: 100%; height: auto;" border="0">`;
        
        document.execCommand('insertHTML', false, imgHtml);
        emailEditor.focus();
        updateHiddenTextarea();
    }
}

/**
 * Cancella formattazione
 */
function clearFormatting() {
    if (!emailEditor) return;
    
    if (confirm('Cancellare tutta la formattazione? Il testo rimarrà ma perderà stili e formattazione.')) {
        const textOnly = emailEditor.textContent || emailEditor.innerText;
        emailEditor.innerHTML = `<div>${escapeHtml(textOnly).replace(/\n/g, '<br>')}</div>`;
        emailEditor.focus();
        updateHiddenTextarea();
    }
}

// ==================== INIZIALIZZAZIONE AUTOMATICA ====================
document.addEventListener('DOMContentLoaded', function() {
    // Inizializza automaticamente se il container esiste
    if (document.getElementById('emailEditorContainer')) {
        initEmailEditor();
    }
});
