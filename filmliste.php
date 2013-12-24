#!/usr/bin/env php
<?php

/*
 * Lädt die Filmliste von MediathekView, filtert die Informationen,
 * wandelt Datum/Zeit und Länge um und speichert alles als JSON.
 *
 * Dieses Script kann per Browser oder direkt per Kommandozeile/Cronjob
 * aufgerufen werden.
 *
 * 2013 Aki Alexandra Nofftz <aki@nofftz.name>
 * Verfügbar gemäß GPL v3
 */

date_default_timezone_set('Europe/Berlin');
header('Content-Type: text/plain');

/** Abstrakter XML-Parser passend für Mediathek-Dateien ***********************/

abstract class AbstractParser
{
	abstract function getXmlUrl();
	abstract function startElement($parser, $name, $attrs);
	abstract function endElement($parser, $name);
	abstract function charData($parser, $data);

	function getParser()
	{
		$parser = xml_parser_create();
		xml_set_element_handler($parser,
			array($this, 'startElement'),
			array($this, 'endElement'));
		xml_set_character_data_handler($parser,
			array($this, 'charData'));
		return $parser;
	}

	function parse()
	{
		$parser = $this->getParser();
		$stream = fopen($this->getXmlUrl(), 'r');
		while ($buffer = fread($stream, 4096)) {
			if (!xml_parse($parser, $buffer, feof($stream))) {
				die(sprintf("XML error: %s at line %d",
					xml_error_string(xml_get_error_code($parser)),
					xml_get_current_line_number($parser)));
			}
		}
		xml_parser_free($parser);
		fclose($stream);
	}

	/**
	 * Mediathek Datum/Zeit-Felder in Timestamp umwandeln
	 */
	function getTimestamp($datum, $zeit)
	{
		if ($zeit == '') {
			$zeit = '00:00:00';
		}
		$datetime = DateTime::createFromFormat('j.n.Y H:i:s',
			$datum . ' ' . $zeit);
		if (!$datetime) {
			return 0;
		}
		return $datetime->getTimestamp();
	}
}


/** SCHRITT 1: Masterliste holen und parsen ***********************************/

class MediathekParser extends AbstractParser
{
	private $modus = '';
	private $url = '';
	private $prio = 0;
	private $timestamp = 0;

	private $urlNeu;
	private $prioNeu;
	private $datum;
	private $zeit;

	function getXmlUrl()
	{
		return 'http://zdfmediathk.sourceforge.net/update.xml';
	}

	function startElement($parser, $name, $attrs)
	{
		switch ($name) {
			case 'URL':
				$this->modus = 'URL';
				$this->urlNeu = '';
				break;

			case 'DATUM':
				$this->modus = 'DATUM';
				$this->datum = '';
				break;

			case 'ZEIT':
				$this->modus = 'ZEIT';
				$this->zeit = '';
				break;

			case 'PRIO':
				$this->modus = 'PRIO';
				$this->prioNeu = '';
				break;
		}
	}

	function endElement($parser, $name)
	{
		switch ($name)
		{
			case 'SERVER':
				// Priorität höher?
				if ($this->prioNeu <= $this->prio)
					break;

				// Timestamp höher?
				$timestamp = $this->getTimestamp($this->datum, $this->zeit);
				if ($timestamp <= $this->timestamp)
					break;

				// neue Daten übernehmen
				$this->timestamp = $timestamp;
				$this->prio = $this->prioNeu;
				$this->url = $this->urlNeu;
				break;

			case 'PRIO':
				$this->prioNeu = intval($this->prioNeu);
				break;
		}
	}

	function charData($parser, $data)
	{
		switch ($this->modus)
		{
			case 'URL':
				$this->urlNeu .= $data;
				break;

			case 'DATUM':
				$this->datum .= $data;
				break;

			case 'ZEIT':
				$this->zeit .= $data;
				break;

			case 'PRIO':
				$this->prioNeu .= $data;
		}
	}

	function getUrl()
	{
		if ($this->url === '') {
			$this->parse();
		}
		return $this->url;
	}
}

$mediathek = (new MediathekParser())->getUrl();


/** SCHRITT 2: FILMLISTE ******************************************************/

class FilmlisteParser extends AbstractParser
{
	private $url;
	private $modus = '';
	private $mapping = array();
	private $liste = array();
	private $element;
	private $film;

	private $filter = array(
		'Sender',
		'Titel',
		'Datum',
		'Zeit',
		'Dauer',
		'Url'
	);

	function FilmlisteParser($url)
	{
		if (preg_match('/\.bz2$/', $url)) {
			$url = 'compress.bzip2://' . $url;
		}
		$this->url = $url;
	}

	function getXmlUrl()
	{
		return $this->url;
	}

	function startElement($parser, $name, $attrs)
	{
		switch ($name)
		{
			case 'FELDINFO':
				$this->modus = 'FELDINFO';
				break;

			case 'X':
				$this->modus = 'FILM';
				$this->film = array(
					'Dauer' => '',
					'Datum' => '',
					'Zeit' => ''
				);
				break;

			default:
				if ($this->modus === 'FELDINFO' || $this->modus === 'FILM') {
					$this->modus .= '_ELEMENT';
					$this->element = $name;

					if ($this->modus === 'FELDINFO_ELEMENT') {
						$this->mapping[$name] = '';
					}
				}
		}
	}

	function endElement($parser, $name)
	{
		switch ($name)
		{
			case 'FELDINFO':
				$this->modus = '';
				break;

			case 'X':
				// Datum/Zeit verarbeiten
				$film = $this->film;
				$film['timestamp'] = $this->getTimestamp($film['Datum'],
					$film['Zeit']);

				// Länge verarbeiten
				$dauer = explode(':', $film['Dauer']);
				if (count($dauer) != 3) {
					$dauer = 0;
				} else {
					$dauer = $dauer[0] * 3600 + $dauer[1] * 60 + $dauer[2];
				}
				$film['Dauer'] = $dauer;

				// Alte Sendungen ignorieren
				if ((time() - $film['timestamp']) > (3600 * 24 * 30)) {
					$this->liste[] = $film;
				}

				$this->modus = '';
				$this->film = null;
				break;

			default:
				if ($this->modus === 'FELDINFO_ELEMENT') {
					$this->element = null;
					$this->modus = 'FELDINFO';
				} else if ($this->modus === 'FILM_ELEMENT') {
					$this->element = null;
					$this->modus = 'FILM';
				}
		}
	}

	function charData($parser, $data)
	{
		switch ($this->modus)
		{
			case 'FELDINFO_ELEMENT':
				$this->mapping[$this->element] .= $data;
				break;

			case 'FILM_ELEMENT':
				$key = $this->mapping[$this->element];
				if (in_array($key, $this->filter)) {
					if (!array_key_exists($key, $this->film)) {
						$this->film[$key] = '';
					}
					$this->film[$key] .= $data;
				}
				break;
		}
	}

	function getListe()
	{
		if (count($this->liste) === 0) {
			$this->parse();
		}
		return $this->liste;
	}
}


/*** SCHRITT 3: Als JSON speichern *******************************************/

echo "Hole $mediathek ...\n";
$liste = (new FilmlisteParser($mediathek))->getListe();
file_put_contents('filmliste.json', json_encode($liste));
echo count($liste) . " Sendungen gespeichert!\n";

