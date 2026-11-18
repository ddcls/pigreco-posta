<?php
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
 * Allegati XML resi leggibili (xml_allegato.php, xml-allegati.js).
 *
 * Riconosce il tipo dal nome dell'elemento radice, senza badare al namespace
 * (i messaggi dello SdI ne usano di diversi a seconda del tipo):
 * - postacert        → dati di certificazione della PEC (daticert.xml)
 * - RicevutaConsegna, RicevutaImpossibilitaRecapito, RicevutaScarto,
 *   NotificaEsito, NotificaDecorrenzaTermini, AttestazioneTrasmissioneFattura,
 *   NotificaEsitoCommittente, ScartoEsitoCommittente, FileMetadati
 *                    → notifiche del Sistema di Interscambio (RC, MC, NS, NE,
 *                      DT, AT, EC, SE, MT)
 * - FatturaElettronica / FatturaElettronicaSemplificata → riepilogo della fattura;
 *   la FatturaElettronica anche impaginata con il foglio di stile ufficiale
 *   dell'Agenzia delle Entrate (fatturapa/FS122.xsl, impagina())
 * - qualsiasi altro XML → solo il testo ordinato
 *
 * I .xml.p7m (firmati CAdES) vengono aperti con `openssl cms` senza
 * verificare la firma. Il risultato è un array di soli testi: la pagina li
 * mette nel DOM con textContent, mai come HTML.
 *
 * Sicurezza: niente DTD (un XML con <!DOCTYPE si mostra come testo, senza
 * leggerlo: niente entità esterne né "billion laughs"), niente rete
 * (LIBXML_NONET), dimensione massima MAX_BYTE.
 */
final class XmlAllegato
{
    public const MAX_BYTE = 5 * 1024 * 1024;

    /** Namespace della FatturaPA 1.2, l'unico che FS122.xsl conosce. */
    private const NS_FATTURA_12 = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';

    private const FOGLIO_FATTURA = __DIR__ . '/fatturapa/FS122.xsl';

    /** Righe della fattura mostrate al massimo (le altre sono nell'XML). */
    private const MAX_LINEE = 60;

    private const TIPI_PEC = [
        'accettazione' => ['ok', 'Ricevuta di accettazione',
            'Il gestore del mittente ha accettato il messaggio e lo sta consegnando.'],
        'non-accettazione' => ['errore', 'Avviso di non accettazione',
            'Il gestore del mittente non ha accettato il messaggio: non è stato inviato.'],
        'presa-in-carico' => ['info', 'Ricevuta di presa in carico',
            'Il gestore del destinatario ha preso in carico il messaggio.'],
        'avvenuta-consegna' => ['ok', 'Ricevuta di avvenuta consegna',
            'Il messaggio è stato consegnato nella casella del destinatario.'],
        'errore-consegna' => ['errore', 'Avviso di mancata consegna',
            'Il messaggio non è stato consegnato al destinatario.'],
        'preavviso-errore-consegna' => ['attenzione', 'Preavviso di mancata consegna',
            'Il messaggio non risulta ancora consegnato: il gestore continua a provare.'],
        'rilevazione-virus' => ['errore', 'Avviso di rilevazione virus',
            'Nel messaggio è stato trovato un virus: non è stato consegnato.'],
        'posta-certificata' => ['info', 'Dati di certificazione della PEC',
            'Provano chi ha inviato il messaggio, a chi e quando.'],
    ];

    private const ERRORI_PEC = [
        'no-dest' => 'la casella del destinatario non esiste',
        'no-dominio' => 'il dominio del destinatario non esiste',
        'virus' => 'nel messaggio è stato trovato un virus',
        'altro' => 'errore del gestore',
    ];

    private const TIPI_DOCUMENTO = [
        'TD01' => 'Fattura', 'TD02' => 'Acconto/anticipo su fattura',
        'TD03' => 'Acconto/anticipo su parcella', 'TD04' => 'Nota di credito',
        'TD05' => 'Nota di debito', 'TD06' => 'Parcella',
        'TD07' => 'Fattura semplificata', 'TD08' => 'Nota di credito semplificata',
        'TD09' => 'Nota di debito semplificata',
        'TD16' => 'Integrazione fattura reverse charge interno',
        'TD17' => 'Integrazione/autofattura per acquisto servizi dall\'estero',
        'TD18' => 'Integrazione per acquisto di beni intracomunitari',
        'TD19' => 'Integrazione/autofattura per acquisto di beni ex art. 17 c. 2',
        'TD20' => 'Autofattura per regolarizzazione',
        'TD21' => 'Autofattura per splafonamento',
        'TD22' => 'Estrazione beni da deposito IVA',
        'TD23' => 'Estrazione beni da deposito IVA con versamento dell\'IVA',
        'TD24' => 'Fattura differita (art. 21 c. 4 lett. a)',
        'TD25' => 'Fattura differita (art. 21 c. 4 terzo periodo lett. b)',
        'TD26' => 'Cessione di beni ammortizzabili e passaggi interni',
        'TD27' => 'Fattura per autoconsumo o cessioni gratuite',
        'TD28' => 'Acquisti da San Marino con IVA',
        'TD29' => 'Comunicazione per omessa o irregolare fatturazione',
    ];

    private const MODALITA_PAGAMENTO = [
        'MP01' => 'contanti', 'MP02' => 'assegno', 'MP03' => 'assegno circolare',
        'MP05' => 'bonifico', 'MP08' => 'carta di pagamento', 'MP09' => 'RID',
        'MP12' => 'RIBA', 'MP19' => 'SEPA Direct Debit', 'MP23' => 'PagoPA',
    ];

    /** Il nome del file fa pensare a un XML (anche firmato)? */
    public static function eXml(string $nome, string $tipoMime = ''): bool
    {
        $nome = strtolower(trim($nome));
        if (preg_match('/\.xml(\.p7m)?$/', $nome)) {
            return true;
        }
        $tipoMime = strtolower($tipoMime);
        return $tipoMime === 'application/xml' || $tipoMime === 'text/xml';
    }

    /**
     * Analizza il contenuto dell'allegato.
     *
     * @return array{tipo:string,titolo:string,esito:string,frase:string,
     *               righe:list<array{0:string,1:string}>,
     *               sezioni:list<array{titolo:string,righe:list<array{0:string,1:string}>}>,
     *               avvisi:list<string>,testo:string,firmato:bool}
     */
    public static function analizza(string $contenuto, string $nome): array
    {
        $risultato = [
            'tipo' => 'xml',
            'titolo' => 'File XML',
            'esito' => 'info',
            'frase' => '',
            'righe' => [],
            'sezioni' => [],
            'avvisi' => [],
            'testo' => '',
            'firmato' => false,
            'impaginabile' => false,
        ];

        if (strlen($contenuto) > self::MAX_BYTE) {
            $risultato['avvisi'][] = 'Il file è troppo grande per essere mostrato: scaricalo.';
            return $risultato;
        }

        $preparato = self::prepara($contenuto, $nome);
        $risultato['firmato'] = $preparato['firmato'];
        array_push($risultato['avvisi'], ...$preparato['avvisi']);
        $doc = $preparato['doc'];
        if ($doc === null) {
            $risultato['testo'] = $preparato['testo'];
            return $risultato;
        }

        $doc->formatOutput = true;
        $doc->encoding = 'UTF-8';
        $risultato['testo'] = self::testoUtf8((string)$doc->saveXML($doc->documentElement));

        $radice = $doc->documentElement;
        $interpretato = match ($radice->localName) {
            'postacert' => self::daticert($radice),
            'RicevutaConsegna', 'RicevutaImpossibilitaRecapito', 'RicevutaScarto',
            'NotificaEsito', 'NotificaDecorrenzaTermini', 'AttestazioneTrasmissioneFattura',
            'NotificaEsitoCommittente', 'ScartoEsitoCommittente', 'FileMetadati'
                => self::notificaSdi($radice),
            'FatturaElettronica', 'FatturaElettronicaSemplificata' => self::fattura($radice),
            default => null,
        };
        if ($radice->localName === 'FatturaElettronica' && self::xsltDisponibile()) {
            $risultato['impaginabile'] = true;
        }

        if ($interpretato !== null) {
            $risultato = array_merge($risultato, $interpretato);
        } else {
            $risultato['titolo'] = 'File XML (' . $radice->localName . ')';
        }
        if ($risultato['firmato']) {
            $risultato['avvisi'][] = 'File firmato digitalmente (.p7m): qui si legge il contenuto, la firma non viene verificata.';
        }
        return $risultato;
    }

    /**
     * p7m aperto, BOM tolto, DOCTYPE rifiutato, XML letto senza rete.
     *
     * @return array{doc:?DOMDocument, testo:string, firmato:bool, avvisi:list<string>}
     */
    private static function prepara(string $contenuto, string $nome): array
    {
        $esito = ['doc' => null, 'testo' => '', 'firmato' => false, 'avvisi' => []];

        if (preg_match('/\.p7m$/i', $nome)) {
            $estratto = self::estraiDaP7m($contenuto);
            if ($estratto === null) {
                $esito['avvisi'][] = 'Non è stato possibile aprire il file firmato (.p7m): scaricalo e aprilo con il programma della firma digitale.';
                return $esito;
            }
            $contenuto = $estratto;
            $esito['firmato'] = true;
        }

        // Togli il BOM: DOMDocument lo accetta, ma il testo mostrato no
        if (strncmp($contenuto, "\xEF\xBB\xBF", 3) === 0) {
            $contenuto = substr($contenuto, 3);
        }

        if (preg_match('/<!DOCTYPE/i', $contenuto)) {
            $esito['avvisi'][] = 'Il file contiene una dichiarazione DOCTYPE: per sicurezza viene mostrato solo il testo, senza interpretarlo.';
            $esito['testo'] = self::testoUtf8($contenuto);
            return $esito;
        }

        $doc = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $precedente = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($contenuto, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($precedente);

        if (!$ok || !$doc->documentElement) {
            $esito['avvisi'][] = 'Il file non è un XML valido: viene mostrato così com\'è.';
            $esito['testo'] = self::testoUtf8($contenuto);
            return $esito;
        }
        $esito['doc'] = $doc;
        return $esito;
    }

    public static function xsltDisponibile(): bool
    {
        return class_exists('XSLTProcessor') && is_file(self::FOGLIO_FATTURA);
    }

    /**
     * La fattura impaginata (HTML) con il foglio di stile ufficiale FS122.xsl,
     * o null se l'allegato non è una FatturaElettronica leggibile.
     * Le fatture 1.1 (namespace di fatturapa.gov.it) passano al foglio con il
     * namespace della 1.2: i nomi degli elementi sono gli stessi.
     */
    public static function impagina(string $contenuto, string $nome): ?string
    {
        if (strlen($contenuto) > self::MAX_BYTE || !self::xsltDisponibile()) {
            return null;
        }
        $doc = self::prepara($contenuto, $nome)['doc'];
        if ($doc === null || $doc->documentElement->localName !== 'FatturaElettronica') {
            return null;
        }

        $ns = (string)$doc->documentElement->namespaceURI;
        if ($ns !== self::NS_FATTURA_12) {
            $xml = (string)$doc->saveXML();
            $xml = $ns !== ''
                ? str_replace('"' . $ns . '"', '"' . self::NS_FATTURA_12 . '"', $xml)
                : $xml;
            $doc = new DOMDocument();
            $precedente = libxml_use_internal_errors(true);
            $ok = $doc->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($precedente);
            if (!$ok || $doc->documentElement->namespaceURI !== self::NS_FATTURA_12) {
                return null;
            }
        }

        $foglio = new DOMDocument();
        if (!$foglio->load(self::FOGLIO_FATTURA, LIBXML_NONET)) {
            return null;
        }
        $xslt = new XSLTProcessor();
        // Il foglio è il nostro, ma per sicurezza niente scritture né rete
        $xslt->setSecurityPrefs(XSL_SECPREF_DEFAULT | XSL_SECPREF_READ_NETWORK);
        if (!@$xslt->importStylesheet($foglio)) {
            return null;
        }
        $html = @$xslt->transformToXml($doc);
        return ($html === false || trim($html) === '') ? null : $html;
    }

    // ================================================================ PEC

    private static function daticert(DOMElement $radice): array
    {
        $tipo = $radice->getAttribute('tipo');
        $errore = $radice->getAttribute('errore');
        [$esito, $titolo, $frase] = self::TIPI_PEC[$tipo]
            ?? ['info', 'Dati di certificazione della PEC', ''];

        if ($errore !== '' && $errore !== 'nessuno') {
            $esito = 'errore';
        }

        $intestazione = self::trova($radice, 'intestazione');
        $dati = self::trova($radice, 'dati');
        $righe = [];

        if ($errore !== '' && $errore !== 'nessuno') {
            $motivo = self::ERRORI_PEC[$errore] ?? $errore;
            $esteso = $dati ? self::val($dati, 'errore-esteso') : '';
            $righe[] = ['Motivo', $esteso !== '' ? ucfirst($motivo) . ': ' . $esteso : ucfirst($motivo)];
        }

        if ($intestazione) {
            self::aggiungi($righe, 'Mittente', self::val($intestazione, 'mittente'));
            $destinatari = [];
            foreach (self::figli($intestazione, 'destinatari') as $d) {
                $indirizzo = trim($d->textContent);
                if ($indirizzo === '') {
                    continue;
                }
                $destinatari[] = $indirizzo . match ($d->getAttribute('tipo')) {
                    'certificato' => ' (PEC)',
                    'esterno' => ' (email non PEC)',
                    default => '',
                };
            }
            self::aggiungi($righe, count($destinatari) > 1 ? 'Destinatari' : 'Destinatario', implode("\n", $destinatari));
            self::aggiungi($righe, 'Oggetto', self::val($intestazione, 'oggetto'));
        }

        if ($dati) {
            self::aggiungi($righe, 'Consegnato a', self::val($dati, 'consegna'));
            $data = self::trova($dati, 'data');
            if ($data) {
                $quando = trim(self::val($data, 'giorno') . ' alle ' . self::val($data, 'ora'));
                $zona = $data->getAttribute('zona');
                if (preg_match('/^([+-])(\d{2})(\d{2})$/', $zona, $m)) {
                    $quando .= ' (UTC' . $m[1] . $m[2] . ':' . $m[3] . ')';
                }
                self::aggiungi($righe, 'Data e ora', $quando === 'alle' ? '' : $quando);
            }
            self::aggiungi($righe, 'Gestore', self::val($dati, 'gestore-emittente'));
            self::aggiungi($righe, 'Identificativo', self::val($dati, 'identificativo'));
            self::aggiungi($righe, 'Message-ID', self::val($dati, 'msgid'));
            $ricevuta = self::trova($dati, 'ricevuta');
            if ($ricevuta && $ricevuta->getAttribute('tipo') !== '') {
                self::aggiungi($righe, 'Ricevuta richiesta', match ($ricevuta->getAttribute('tipo')) {
                    'completa' => 'completa (con copia del messaggio)',
                    'breve' => 'breve',
                    'sintetica' => 'sintetica',
                    default => $ricevuta->getAttribute('tipo'),
                });
            }
        }
        if ($intestazione) {
            self::aggiungi($righe, 'Risposte a', self::val($intestazione, 'risposte'));
        }

        return [
            'tipo' => 'daticert',
            'titolo' => $titolo,
            'esito' => $esito,
            'frase' => $frase,
            'righe' => $righe,
        ];
    }

    // ================================================================ SdI

    private static function notificaSdi(DOMElement $radice): array
    {
        $nome = $radice->localName;
        $righe = [];
        $sezioni = [];
        $esito = 'info';
        $frase = '';
        $sigla = '';
        $titolo = '';

        // EsitoCommittente è dentro NotificaEsito; negli altri messaggi i
        // campi dell'esito stanno direttamente sotto la radice
        $esitoCommittente = $nome === 'NotificaEsito' ? self::trova($radice, 'EsitoCommittente') : $radice;
        $riferimento = $esitoCommittente ? self::trova($esitoCommittente, 'RiferimentoFattura') : null;

        switch ($nome) {
            case 'RicevutaConsegna':
                [$sigla, $esito, $titolo] = ['RC', 'ok', 'Fattura consegnata'];
                $frase = 'Lo SdI ha consegnato la fattura al destinatario.';
                break;
            case 'RicevutaImpossibilitaRecapito':
                [$sigla, $esito, $titolo] = ['MC', 'attenzione', 'Fattura non recapitata'];
                $frase = 'Lo SdI non è riuscito a recapitarla: la fattura è comunque emessa e il destinatario la trova nella sua area riservata (Fatture e Corrispettivi).';
                break;
            case 'RicevutaScarto':
                [$sigla, $esito, $titolo] = ['NS', 'errore', 'Fattura scartata'];
                $frase = 'Lo SdI ha scartato la fattura: non è emessa. Va corretta e inviata di nuovo.';
                break;
            case 'NotificaEsito':
            case 'NotificaEsitoCommittente':
                $codice = $esitoCommittente ? self::val($esitoCommittente, 'Esito') : '';
                $sigla = $nome === 'NotificaEsito' ? 'NE' : 'EC';
                if ($codice === 'EC01') {
                    [$esito, $titolo] = ['ok', 'Fattura accettata dal cliente'];
                    $frase = 'Il cliente (pubblica amministrazione) ha accettato la fattura.';
                } elseif ($codice === 'EC02') {
                    [$esito, $titolo] = ['errore', 'Fattura rifiutata dal cliente'];
                    $frase = 'Il cliente (pubblica amministrazione) ha rifiutato la fattura.';
                } else {
                    $titolo = 'Esito del cliente sulla fattura';
                }
                if ($nome === 'NotificaEsitoCommittente') {
                    $frase = 'Esito inviato dal committente allo SdI.';
                }
                break;
            case 'NotificaDecorrenzaTermini':
                [$sigla, $esito, $titolo] = ['DT', 'info', 'Decorrenza dei termini'];
                $frase = 'Sono passati 15 giorni senza una risposta del cliente (pubblica amministrazione): la procedura sulla fattura è chiusa.';
                break;
            case 'AttestazioneTrasmissioneFattura':
                [$sigla, $esito, $titolo] = ['AT', 'attenzione', 'Fattura trasmessa ma non recapitata'];
                $frase = 'Lo SdI non è riuscito a recapitare la fattura alla pubblica amministrazione: questa attestazione va mandata al cliente.';
                break;
            case 'ScartoEsitoCommittente':
                [$sigla, $esito, $titolo] = ['SE', 'errore', 'Esito del committente scartato'];
                $scarto = self::val($radice, 'Scarto');
                $frase = match ($scarto) {
                    'EN00' => 'Lo SdI ha scartato l\'esito: la notifica non è conforme al formato.',
                    'EN01' => 'Lo SdI ha scartato l\'esito: la notifica non è ammissibile (per esempio oltre i termini).',
                    default => 'Lo SdI ha scartato l\'esito inviato dal committente.',
                };
                break;
            case 'FileMetadati':
                [$sigla, $esito, $titolo] = ['MT', 'info', 'Metadati della fattura ricevuta'];
                $frase = 'Accompagna una fattura ricevuta attraverso lo SdI.';
                break;
        }

        if ($riferimento) {
            $numero = self::val($riferimento, 'NumeroFattura');
            $anno = self::val($riferimento, 'AnnoFattura');
            $posizione = self::val($riferimento, 'PosizioneFattura');
            $testo = trim('n. ' . $numero . ($anno !== '' ? ' del ' . $anno : ''));
            if ($posizione !== '' && $posizione !== '1') {
                $testo .= ' (posizione ' . $posizione . ' nel file)';
            }
            self::aggiungi($righe, 'Fattura', $numero !== '' ? $testo : '');
        }
        self::aggiungi($righe, 'File', self::val($radice, 'NomeFile'));
        self::aggiungi($righe, 'Identificativo SdI', self::val($radice, 'IdentificativoSdI'));

        if ($esitoCommittente && in_array($nome, ['NotificaEsito', 'NotificaEsitoCommittente'], true)) {
            $codice = self::val($esitoCommittente, 'Esito');
            self::aggiungi($righe, 'Esito', $codice === 'EC01' ? 'EC01 – accettata' : ($codice === 'EC02' ? 'EC02 – rifiutata' : $codice));
            self::aggiungi($righe, 'Motivo', self::val($esitoCommittente, 'Descrizione'));
        }
        if ($nome === 'ScartoEsitoCommittente') {
            self::aggiungi($righe, 'Codice di scarto', self::val($radice, 'Scarto'));
        }

        self::aggiungi($righe, 'Ricevuta dallo SdI', self::dataOra(self::val($radice, 'DataOraRicezione')));
        self::aggiungi($righe, 'Consegnata il', self::dataOra(self::val($radice, 'DataOraConsegna')));
        $destinatario = self::trova($radice, 'Destinatario');
        if ($destinatario) {
            self::aggiungi($righe, 'Destinatario', trim(self::val($destinatario, 'Descrizione') . ' (' . self::val($destinatario, 'Codice') . ')', ' ()'));
        }
        self::aggiungi($righe, 'Codice destinatario', self::val($radice, 'CodiceDestinatario'));
        self::aggiungi($righe, 'Formato', self::val($radice, 'Formato'));
        self::aggiungi($righe, 'Tentativi di invio', self::val($radice, 'TentativiInvio'));
        if ($nome !== 'NotificaEsito') {
            self::aggiungi($righe, 'Descrizione', self::val($radice, 'Descrizione'));
        }
        self::aggiungi($righe, 'Message ID', self::val($radice, 'MessageId'));
        self::aggiungi($righe, 'Note', self::val($radice, 'Note'));

        $lista = self::trova($radice, 'ListaErrori');
        if ($lista) {
            $errori = [];
            foreach (self::figli($lista, 'Errore') as $i => $e) {
                $testo = self::val($e, 'Descrizione');
                $suggerimento = self::val($e, 'Suggerimento');
                if ($suggerimento !== '') {
                    $testo .= "\nSuggerimento: " . $suggerimento;
                }
                $errori[] = ['Codice ' . (self::val($e, 'Codice') ?: (string)($i + 1)), $testo];
            }
            if ($errori) {
                $sezioni[] = ['titolo' => count($errori) === 1 ? 'Errore' : 'Errori (' . count($errori) . ')', 'righe' => $errori];
            }
        }

        return [
            'tipo' => 'sdi',
            'titolo' => $titolo . ($sigla !== '' ? ' (' . $sigla . ')' : ''),
            'esito' => $esito,
            'frase' => $frase,
            'righe' => $righe,
            'sezioni' => $sezioni,
        ];
    }

    // ================================================================ fattura

    private static function fattura(DOMElement $radice): array
    {
        $header = self::trova($radice, 'FatturaElettronicaHeader');
        $corpi = self::figli($radice, 'FatturaElettronicaBody');
        $righe = [];
        $sezioni = [];

        if ($header) {
            $cedente = self::trova($header, 'CedentePrestatore');
            $cessionario = self::trova($header, 'CessionarioCommittente');
            self::aggiungi($righe, 'Fornitore', $cedente ? self::soggetto($cedente) : '');
            self::aggiungi($righe, 'Cliente', $cessionario ? self::soggetto($cessionario) : '');
        }

        $titolo = 'Fattura elettronica';
        foreach ($corpi as $n => $corpo) {
            $generali = self::trova($corpo, 'DatiGenerali/DatiGeneraliDocumento');
            $tipoDoc = $generali ? self::val($generali, 'TipoDocumento') : '';
            $numero = $generali ? self::val($generali, 'Numero') : '';
            $data = $generali ? self::data(self::val($generali, 'Data')) : '';
            $divisa = $generali ? (self::val($generali, 'Divisa') ?: 'EUR') : 'EUR';
            $nomeTipo = self::TIPI_DOCUMENTO[$tipoDoc] ?? ($tipoDoc !== '' ? 'Documento ' . $tipoDoc : 'Fattura');
            $intestazioneDoc = trim($nomeTipo . ($numero !== '' ? ' n. ' . $numero : '') . ($data !== '' ? ' del ' . $data : ''));

            $dettaglio = [];
            if (count($corpi) > 1) {
                $dettaglio[] = ['Documento', $intestazioneDoc];
            } else {
                $titolo = $intestazioneDoc;
            }

            $totale = $generali ? self::val($generali, 'ImportoTotaleDocumento') : '';
            if ($totale === '') {
                // Senza il totale dichiarato: imponibile + imposta del riepilogo
                $somma = 0.0;
                $trovato = false;
                foreach (self::figli(self::trova($corpo, 'DatiBeniServizi') ?? $corpo, 'DatiRiepilogo') as $r) {
                    $somma += (float)self::val($r, 'ImponibileImporto') + (float)self::val($r, 'Imposta');
                    $trovato = true;
                }
                if ($trovato) {
                    $totale = (string)$somma;
                }
            }
            if ($totale === '' && $radice->localName === 'FatturaElettronicaSemplificata') {
                $somma = 0.0;
                foreach (self::figli($corpo, 'DatiBeniServizi') as $b) {
                    $somma += (float)self::val($b, 'Importo');
                }
                $totale = $somma > 0 ? (string)$somma : '';
            }
            self::aggiungi($dettaglio, 'Totale documento', $totale !== '' ? self::importo($totale, $divisa) : '');

            $causali = [];
            if ($generali) {
                foreach (self::figli($generali, 'Causale') as $c) {
                    $causali[] = trim($c->textContent);
                }
            }
            self::aggiungi($dettaglio, 'Causale', implode(' ', array_filter($causali)));

            foreach (self::figli($corpo, 'DatiPagamento') as $pagamento) {
                foreach (self::figli($pagamento, 'DettaglioPagamento') as $p) {
                    $parti = [];
                    $importo = self::val($p, 'ImportoPagamento');
                    if ($importo !== '') {
                        $parti[] = self::importo($importo, $divisa);
                    }
                    $scadenza = self::data(self::val($p, 'DataScadenzaPagamento'));
                    if ($scadenza !== '') {
                        $parti[] = 'entro il ' . $scadenza;
                    }
                    $modo = self::val($p, 'ModalitaPagamento');
                    if ($modo !== '') {
                        $parti[] = 'con ' . (self::MODALITA_PAGAMENTO[$modo] ?? $modo);
                    }
                    $iban = self::val($p, 'IBAN');
                    if ($iban !== '') {
                        $parti[] = 'IBAN ' . $iban;
                    }
                    self::aggiungi($dettaglio, 'Pagamento', implode(', ', $parti));
                }
            }

            $allegati = [];
            foreach (self::figli($corpo, 'Allegati') as $a) {
                $allegati[] = self::val($a, 'NomeAttachment');
            }
            self::aggiungi($dettaglio, 'Allegati nella fattura', implode("\n", array_filter($allegati)));

            if (count($corpi) > 1) {
                $sezioni[] = ['titolo' => 'Documento ' . ($n + 1) . ' di ' . count($corpi), 'righe' => $dettaglio];
            } else {
                $righe = array_merge($righe, $dettaglio);
            }

            // Righe della fattura
            $beni = self::trova($corpo, 'DatiBeniServizi');
            $linee = [];
            if ($beni) {
                foreach (self::figli($beni, 'DettaglioLinee') as $l) {
                    if (count($linee) >= self::MAX_LINEE) {
                        break;
                    }
                    $descrizione = self::val($l, 'Descrizione');
                    $qta = self::val($l, 'Quantita');
                    $um = self::val($l, 'UnitaMisura');
                    $prezzo = self::val($l, 'PrezzoTotale');
                    $iva = self::val($l, 'AliquotaIVA');
                    $parti = [];
                    if ($qta !== '') {
                        $parti[] = self::numero($qta) . ($um !== '' ? ' ' . $um : '');
                    }
                    if ($prezzo !== '') {
                        $parti[] = self::importo($prezzo, $divisa);
                    }
                    if ($iva !== '') {
                        $parti[] = 'IVA ' . self::numero($iva) . '%';
                    }
                    $linee[] = [$descrizione !== '' ? $descrizione : 'Riga ' . self::val($l, 'NumeroLinea'), implode(' · ', $parti)];
                }
                $tutte = count(self::figli($beni, 'DettaglioLinee'));
                if ($linee) {
                    $titoloLinee = 'Righe' . (count($corpi) > 1 ? ' del documento ' . ($n + 1) : '');
                    if ($tutte > count($linee)) {
                        $titoloLinee .= ' (prime ' . count($linee) . ' di ' . $tutte . ')';
                    }
                    $sezioni[] = ['titolo' => $titoloLinee, 'righe' => $linee];
                }

                $iva = [];
                foreach (self::figli($beni, 'DatiRiepilogo') as $r) {
                    $aliquota = self::val($r, 'AliquotaIVA');
                    $natura = self::val($r, 'Natura');
                    $etichetta = 'IVA ' . self::numero($aliquota) . '%' . ($natura !== '' ? ' (' . $natura . ')' : '');
                    $iva[] = [$etichetta, 'imponibile ' . self::importo(self::val($r, 'ImponibileImporto'), $divisa)
                        . ' · imposta ' . self::importo(self::val($r, 'Imposta'), $divisa)];
                }
                if ($iva) {
                    $sezioni[] = ['titolo' => 'Riepilogo IVA' . (count($corpi) > 1 ? ' del documento ' . ($n + 1) : ''), 'righe' => $iva];
                }
            }
        }

        if (count($corpi) > 1) {
            $titolo = 'Fatture elettroniche (' . count($corpi) . ' documenti)';
        }

        return [
            'tipo' => 'fattura',
            'titolo' => $titolo,
            'esito' => 'info',
            'frase' => $radice->getAttribute('versione') !== '' ? 'Formato ' . $radice->getAttribute('versione') . '.' : '',
            'righe' => $righe,
            'sezioni' => $sezioni,
        ];
    }

    /** Denominazione (o nome e cognome), partita IVA e codice fiscale. */
    private static function soggetto(DOMElement $s): string
    {
        $anagrafici = self::trova($s, 'DatiAnagrafici') ?? $s;
        $anagrafica = self::trova($anagrafici, 'Anagrafica') ?? $anagrafici;
        $nome = self::val($anagrafica, 'Denominazione');
        if ($nome === '') {
            $nome = trim(self::val($anagrafica, 'Nome') . ' ' . self::val($anagrafica, 'Cognome'));
        }
        if ($nome === '') {
            // Fattura semplificata: Denominazione/Nome direttamente sotto il soggetto
            $nome = self::val($s, 'Denominazione') ?: trim(self::val($s, 'Nome') . ' ' . self::val($s, 'Cognome'));
        }
        $parti = [$nome];
        $idIva = self::trova($anagrafici, 'IdFiscaleIVA') ?? self::trova($s, 'IdFiscaleIVA');
        if ($idIva) {
            $parti[] = 'P.IVA ' . self::val($idIva, 'IdPaese') . self::val($idIva, 'IdCodice');
        }
        $cf = self::val($anagrafici, 'CodiceFiscale') ?: self::val($s, 'CodiceFiscale');
        if ($cf !== '') {
            $parti[] = 'C.F. ' . $cf;
        }
        return implode(' · ', array_filter($parti, fn($p) => $p !== ''));
    }

    // ================================================================ p7m

    /** Contenuto di un file firmato CAdES (DER o base64), senza verificare la firma. */
    private static function estraiDaP7m(string $p7m): ?string
    {
        $tentativi = [$p7m];
        $pulito = preg_replace('/-----[^-]+-----|\s+/', '', $p7m);
        if ($pulito !== null && $pulito !== '' && preg_match('/^[A-Za-z0-9+\/=]+$/', $pulito)) {
            $decodificato = base64_decode($pulito, true);
            if ($decodificato !== false) {
                $tentativi[] = $decodificato;
            }
        }
        foreach ($tentativi as $der) {
            $xml = self::opensslCms($der);
            if ($xml !== null) {
                return $xml;
            }
        }
        // Ultima possibilità: l'XML è in chiaro dentro la busta
        if (preg_match('/<\?xml[\s\S]*<\/(?:[\w.-]+:)?Fattura\w*>/', $p7m, $m)) {
            return $m[0];
        }
        return null;
    }

    private static function opensslCms(string $der): ?string
    {
        $dir = sys_get_temp_dir();
        $ingresso = tempnam($dir, 'p7m');
        $uscita = tempnam($dir, 'xml');
        if ($ingresso === false || $uscita === false) {
            return null;
        }
        try {
            file_put_contents($ingresso, $der);
            $proc = proc_open(
                ['openssl', 'cms', '-verify', '-noverify', '-inform', 'DER', '-in', $ingresso, '-out', $uscita],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $tubi
            );
            if (!is_resource($proc)) {
                return null;
            }
            $codice = proc_close($proc);
            $xml = @file_get_contents($uscita);
            return ($codice === 0 && $xml !== false && $xml !== '') ? $xml : null;
        } finally {
            @unlink($ingresso);
            @unlink($uscita);
        }
    }

    // ================================================================ aiuti

    /** Figli diretti con quel nome locale (qualsiasi namespace). */
    private static function figli(DOMNode $nodo, string $nome): array
    {
        $trovati = [];
        foreach ($nodo->childNodes as $figlio) {
            if ($figlio instanceof DOMElement && $figlio->localName === $nome) {
                $trovati[] = $figlio;
            }
        }
        return $trovati;
    }

    /** Primo elemento lungo il percorso "a/b/c" (figli diretti). */
    private static function trova(DOMNode $nodo, string $percorso): ?DOMElement
    {
        foreach (explode('/', $percorso) as $pezzo) {
            $figli = self::figli($nodo, $pezzo);
            if (!$figli) {
                return null;
            }
            $nodo = $figli[0];
        }
        return $nodo instanceof DOMElement ? $nodo : null;
    }

    private static function val(DOMNode $nodo, string $percorso): string
    {
        $el = self::trova($nodo, $percorso);
        return $el ? trim(preg_replace('/[ \t]+/', ' ', $el->textContent)) : '';
    }

    private static function aggiungi(array &$righe, string $etichetta, string $valore): void
    {
        if (trim($valore) !== '') {
            $righe[] = [$etichetta, trim($valore)];
        }
    }

    /** 2026-11-17T10:21:33.000+01:00 → 17/11/2026 10:21:33 */
    private static function dataOra(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}:\d{2}(?::\d{2})?)/', $iso, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]} {$m[4]}";
        }
        return $iso;
    }

    /** 2026-11-17 → 17/11/2026 */
    private static function data(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        return $iso;
    }

    private static function importo(string $valore, string $divisa): string
    {
        if (!is_numeric($valore)) {
            return $valore;
        }
        $testo = number_format((float)$valore, 2, ',', '.');
        return $divisa === 'EUR' ? $testo . ' €' : $testo . ' ' . $divisa;
    }

    /** 1.00 → 1, 22.00 → 22, 2.50 → 2,5 */
    private static function numero(string $valore): string
    {
        if (!is_numeric($valore)) {
            return $valore;
        }
        $testo = rtrim(rtrim(number_format((float)$valore, 4, ',', '.'), '0'), ',');
        return $testo === '' ? '0' : $testo;
    }

    /** Testo sempre in UTF-8 valido (per json_encode). */
    private static function testoUtf8(string $testo): string
    {
        if (!mb_check_encoding($testo, 'UTF-8')) {
            $testo = mb_convert_encoding($testo, 'UTF-8', 'Windows-1252');
        }
        return $testo;
    }
}
