<?php
/**
 * Avvio della sessione della webmail con un cookie dal nome proprio.
 *
 * Il nome predefinito di PHP (PHPSESSID) è lo stesso usato da altre
 * applicazioni PHP sullo stesso server, per esempio PiGreco Archivio: i
 * cookie non distinguono le porte, quindi due app sullo stesso host con lo
 * stesso nome di cookie si sovrascrivono la sessione a vicenda (entrando in
 * una si esce dall'altra).
 */
function avviaSessione() {
    session_name('WEBMAIL_SID');
    session_start();
}
