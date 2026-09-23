# Site Snapshot (pracovní název)

WordPress plugin pro rychlou zálohu webu před úpravami: prohlížeč souborů webu (jako FTP klient), stažení
libovolného souboru nebo složky a **kompletní záloha souborů + databáze do jednoho ZIPu**. Každá záloha obsahuje
i přehled verzí (PHP, databáze, WordPress, server) a přístupových údajů.

Plugin je v `site-snapshot/` – do WordPressu se nahraje jako ZIP této složky (Pluginy → Přidat nový → Nahrát)
nebo zkopírováním do `wp-content/plugins/`. Po aktivaci: **Nástroje → Site Snapshot**.

## Co umí

| Záložka | Obsah |
| --- | --- |
| **Záloha** | Jedno tlačítko → ZIP se soubory webu (`files/`), `database.sql`, `SITE-INFO.txt`, `site-info.json`, `OBNOVA-README.txt`. Volby: jen soubory / jen DB, všechny tabulky v DB, vynechání cache a záloh jiných pluginů. Seznam záloh se stažením a mazáním. |
| **Soubory** | Procházení kořene WordPressu (velikost, datum, práva), stažení souboru, stažení složky jako ZIP, zvýraznění citlivých souborů (`wp-config.php`, `.htaccess`, `.env`…). |
| **Systém a přístupy** | Verze a nastavení WordPressu, PHP (limity, rozšíření), databáze (MySQL/MariaDB verze, znaková sada, velikost), serveru. Přístupové údaje z `wp-config.php` (DB, případné `FTP_*`), seznam administrátorů a formulář pro FTP/hosting přístupy (ukládají se šifrovaně). |
| **Historie** | Audit log: kdo a kdy (uživatel, IP) vytvořil / stáhl / smazal zálohu, stáhl soubor nebo zobrazil přístupové údaje. |

## Jak to funguje

- **Po krocích.** Záloha běží v krátkých AJAX požadavcích (5–20 s, podle `max_execution_time`), takže projde
  i velký web na sdíleném hostingu. Stav se průběžně ukládá; pokud požadavek spadne (timeout), další krok
  vrátí rozepsané soubory na poslední potvrzený stav a pokračuje. Při zavření stránky se záloha pozastaví
  a po návratu pokračuje.
- **Vlastní ZIP writer** (ZIP64, UTF-8 názvy) – na rozdíl od `ZipArchive` archiv jen připisuje, takže vícegigové
  zálohy nejsou kvadraticky pomalé. Již komprimované soubory (obrázky, video, archivy) a soubory > 50 MB se
  ukládají bez komprese.
- **Databáze bez `mysqldump`** – čisté PHP přes `$wpdb`, po 1000 řádcích: `DROP` + `CREATE` + vícerádkové `INSERT`,
  binární data jako hex, `BIT` jako číslo, generované sloupce vynechány, pohledy bez `DEFINER`, `TIMESTAMP` v UTC.
- **Úložiště záloh** je v `wp-content/uploads/site-snapshot-<24 náhodných znaků>/`, chráněné `.htaccess`,
  `web.config` a indexy. Stahuje se jen přes PHP s kontrolou oprávnění a nonce. Nedokončené zálohy maže denní cron
  po 24 h.

## Bezpečnost

- Přístup jen pro `manage_options` (na multisite jen super admin); všechny akce s nonce.
- Prohlížeč souborů pouští jen cesty uvnitř kořene webu (`realpath`), symlinky ven a `..` odmítá; vlastní
  úložiště záloh nezobrazuje ani nestahuje.
- Ručně zadané FTP/hosting údaje jsou šifrované (libsodium, klíč odvozený z `AUTH_KEY`/`AUTH_SALT`) – únik samotné
  databáze je neprozradí. Po změně salts je potřeba je zadat znovu.
- **Záloha obsahuje hesla** (`wp-config.php`, `SITE-INFO.txt`). Po stažení ji ze serveru smažte – na nginx
  `.htaccess` neplatí a ochranou je jen náhodný název složky.

## Omezení

- Jeden soubor se musí zabalit v rámci jednoho kroku – velmi velké soubory (jednotky GB) na hostingu s krátkým
  limitem mohou vyžadovat delší `max_execution_time`.
- Záloha se dočasně ukládá na stejný disk – potřebuje zhruba tolik volného místa, kolik zabírá web.
- Obnova je ruční (postup je v `OBNOVA-README.txt` v každé záloze).

## Pro vývojáře

Filtry: `sitesnap_root` (kořen prohlížeče a zálohy), `sitesnap_excluded_dirs` (vynechané složky),
`sitesnap_step_seconds` (délka kroku).

Požadavky: WordPress 5.6+, PHP 7.4+ (64bit), rozšíření `zlib` (komprese; bez něj se ukládá nekomprimovaně)
a `sodium` (součást PHP 7.2+, jinak WordPress polyfill).

Otestováno na WordPress 7.1.2, PHP 8.5, MariaDB 13 – viz CHANGELOG.
