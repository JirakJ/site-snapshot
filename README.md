# Site Snapshot

WordPress plugin pro rychlou zálohu webu před úpravami: prohlížeč souborů webu (jako FTP klient), stažení
libovolného souboru nebo složky a **kompletní záloha souborů + databáze do jednoho ZIPu**. Každá záloha obsahuje
i přehled verzí (PHP, databáze, WordPress, server) a přístupových údajů.

> *English:* one-click full-site backup (files + database) into a single ZIP from wp-admin, a read-only
> FTP-like file browser, an environment/credentials report and an audit log. Pure PHP, resumable, works on
> shared hosting. The UI and docs are in Czech.

![Site Snapshot – karta Záloha](docs/img/06-zaloha-prubeh.jpg)

## Rychlý start

1. Stáhněte **`site-snapshot-X.Y.Z.zip`** z [posledního releasu](https://github.com/JirakJ/site-snapshot/releases/latest).
2. WordPress: **Pluginy → Přidat plugin → Nahrát plugin** → vybrat ZIP → **Aktivovat**.
3. **Nástroje → Site Snapshot → Vytvořit zálohu** → **Stáhnout ZIP** → zálohu ze serveru **Smazat**.
4. Běží web na **nginx**? Doplňte pravidlo, které plugin zobrazí (viz návod, kapitola 4).

🖱️ **[Ovládání krok za krokem](docs/OVLADANI.md)** – obrázkový průvodce přímo z administrace WordPressu.

📖 **[Kompletní návod](docs/NAVOD.md)** – instalace, zabezpečení (Apache / nginx), FTP přístupy, záloha,
obnova webu ze zálohy, velké weby, řešení problémů.

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
  vrátí rozepsané soubory na poslední potvrzený stav a pokračuje. Po krocích běží i procházení souborů.
  Při zavření stránky se záloha pozastaví: do 15 minut po návratu pokračuje sama, později je v seznamu
  „Přerušeno“ s tlačítkem **Pokračovat**.
- **Celá instalace, i mimo kořen:** kořen WordPressu jde do `files/`; `wp-config.php` o úroveň výš
  a `wp-content` / pluginy / uploads přesunuté mimo ABSPATH (např. Bedrock) jdou do `extra/`.
- **Vlastní ZIP writer** (ZIP64, UTF-8 názvy) – na rozdíl od `ZipArchive` archiv jen připisuje, takže vícegigové
  zálohy nejsou kvadraticky pomalé. Již komprimované soubory (obrázky, video, archivy) a soubory > 50 MB se
  ukládají bez komprese.
- **Databáze bez `mysqldump`** – čisté PHP přes `$wpdb`: `DROP` + `CREATE` + vícerádkové `INSERT`. Stránkuje se
  podle primárního klíče (keyset), takže změny na živém webu během zálohy řádky nepřeskočí ani nezdvojí.
  Velikost dávky se řídí průměrnou délkou řádku (~4 MB na dávku). Binární data se ukládají jako hex, `BIT` jako
  číslo, generované sloupce se vynechávají, pohledy bez `DEFINER`, `TIMESTAMP` v UTC a `SET NAMES` podle
  skutečného charsetu spojení. Tabulky jiné instalace se stejným začátkem prefixu (`wp_shop_` vedle `wp_`)
  se nezahrnou. Tabulky se exportují postupně, takže to není jeden konzistentní snapshot celé DB.
- **Úložiště záloh** je v `wp-content/uploads/site-snapshot-<24 náhodných znaků>/`, chráněné `.htaccess`,
  `web.config` a indexy. Stahuje se jen přes PHP s kontrolou oprávnění a nonce. Nedokončené zálohy maže denní cron
  po 24 h.

## Bezpečnost

- Přístup jen pro `manage_options` (na multisite jen super admin); všechny akce s nonce.
- Prohlížeč souborů pouští jen cesty uvnitř kořene webu (`realpath`), symlinky ven a `..` odmítá; vlastní
  úložiště záloh nezobrazuje ani nestahuje.
- Ručně zadané FTP/hosting údaje jsou šifrované (libsodium, klíč odvozený z `AUTH_KEY`/`AUTH_SALT`) – únik samotné
  databáze je neprozradí. Po změně salts je potřeba je zadat znovu.
- Úložiště záloh chrání na Apache/LiteSpeed `.htaccess` (ověřeno: 403) a na IIS `web.config`. Plugin navíc
  **sám otestuje**, jestli jde kontrolní soubor ze složky záloh stáhnout bez přihlášení (funguje pro jakýkoli
  server, i nginx před Apachem). Pokud ano, zobrazí pravidlo pro nginx (`location ^~ …`, na multisite regex podle
  názvu složky) – ověřeno na nginx 1.31 včetně kódovaných adres, regex bloků pro statické soubory
  a přepisů multisite.
- **Záloha obsahuje hesla** (`wp-config.php`, `SITE-INFO.txt`) – po stažení ji ze serveru smažte.

Bezpečnostní problém nahlaste soukromě přes
[GitHub Security Advisories](https://github.com/JirakJ/site-snapshot/security/advisories/new), ne veřejným issue.

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

Build: `bin/build.sh` → `dist/site-snapshot-<verze>.zip`.

Otestováno na WordPress 7.1.2, PHP 8.5, MariaDB 13, Apache 2.4 a nginx 1.31 – viz [CHANGELOG](CHANGELOG.md).

## Licence

[GPL-2.0-or-later](LICENSE) – stejně jako WordPress.
