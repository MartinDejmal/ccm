# Spuštění a nasazení prvního MVP

## Co již první MVP umí

Přihlášení účtem založeným správcem, přidání vozidla s jednou výchozí nádrží/médiem, zadání, editace a potvrzené smazání tankování a ostatního výdaje, stránkované historie a celkové součty oddělené podle druhu události a měny. Úpravy používají revizi, vytváření záznamů klíč požadavku proti opakovanému odeslání. Změny mají auditní snímky a uživatelská data jsou oddělená vlastnictvím vozidla.

Moje garáž umožňuje úpravu údajů vozidel, archivaci, obnovení a nahrání / odstranění obrázku. Podrobnosti a limity uvádí [návod garáže](moje-garaz.md).

Doplněné jsou filtry období/měny/kategorie/paliva a CSV validace s uloženým náhledem, přeskočením řádků a zrušením dávky. Doplněné jsou také kontrola duplicit, atomické potvrzení importu a vrácení dávky s ochranou pozdějších změn; viz [návod importu](csv-import.md). Samotný upload zůstává pouze náhledem. Záložka Spotřeba doplňuje výpočty plná–plná, vážené průměry, kvalitu a přerušení historie a dodávky elektřiny/AdBlue na vzdálenost; viz [pravidla spotřeby](spotreba.md). Zatím nejsou hotové export, grafy, správa dalších médií, obnova vyřazených záznamů, nativní mobilní přihlášení. [Opakované plány](opakovane-vydaje.md) se zakládají pouze výslovnou volbou a platby se zapisují jako skutečné výdaje. Nejde o dokončení všech kroků implementačního plánu.

Nová migrace `2026_10_22_000002_create_recurring_expense_plans` přidává plány, propojení skutečných plateb a audit. `/ready` vyžaduje její dokončení; deploy skript ji provede před spuštěním aplikace.

Historie tankování a výdajů používá čtvercovou modrou tužku pro úpravu a červený křížek pro smazání s potvrzením. API `DELETE /api/v1/vehicles/{vehicle}/events/{event}` vyžaduje aktuální `revision`; vrací 204 při úspěchu/opakování stejného smazání a 409 při konfliktu nebo archivovaném vozidle. Jde o logické vyřazení s auditem, nikoli fyzické odstranění. Záznam zmizí ze součtů, tankování zanechá mezeru ve výpočtu spotřeby. Aktivní plán propojený přes zdroj nebo platbu se ukončí s důvodem. Obnova zatím nemá uživatelské rozhraní.

## Datový model a migrace

Laravel migrace v database/migrations vytvářejí účty, sessions, cache/queue, číselníky, vozidla, proudy média, společné finanční události, detaily tankování/výdajů a audit. Částky jsou DECIMAL(18,4), měřené hodnoty DECIMAL, vazby mají cizí klíče. Cena tankování je uložena pouze ve společné události. Globálně jedinečný UUID request_key chrání opakování vytvoření a request_hash kontroluje shodu obsahu. Revize chrání editaci před ztrátou souběžných změn.

Migrační krok naplní 22 druhů paliva, 23 kategorií výdajů, CZK/EUR/USD, typy tankování, pneumatik, stylu jízdy, silnic a intervalů. Hodnoty jsou verzované v database/data/catalogs.json; kategorie 7/13 zatím zachovávají původní zdrojové názvy a mají pracovní české popisky. Jednotky a skupiny médií jsou v tomto MVP řízené serverovou validací a metadaty paliv, nikoli všemi samostatnými číselníky cílového modelu. Migrace 2026_10_21_000001_create_import_previews přidává import_batches a import_rows: původní CSV, profil, parametry, souhrn, raw řádky, normalizaci a chyby/varování. V této fázi jsou issues uloženy v textovém JSON poli řádku; samostatná tabulka import_issue není potřeba. Raw CSV je při limitu 2 MiB uchováno v neveřejné databázi, ne v adresáři veřejných uploadů. Migrace 2026_10_21_000002_add_vehicle_garage přidává revision a avatar_path k vozidlům a tabulku vehicle_revisions pro audit. Migrace 2026_10_21_000003_complete_csv_imports přidává revize a potvrzení/vrácení dávek, rozhodnutí o duplicitách, vazby událostí na dávku a řádek a tabulku import_actions. Existující náhledy zůstanou zachované a vyžadují novou kontrolu před zápisem. Migrace 2026_10_22_000001_add_consumption_history_break přidává fuelings.break_before (výchozí false); pořadí používá existující day_sequence. Výsledky spotřeby se neukládají, počítají se při čtení. Plánovací tabulky vzniknou s navazující funkcionalitou.

## Konfigurace externí MariaDB

DB server není součástí produkčního Compose. Správce vytvoří samostatnou databázi CCM s utf8mb4 a účet s přístupem z aplikačního hostitele. Není nutné poskytovat produkční heslo vývojáři.

Zkopírujte .env.example do souboru mimo checkout, například /opt/ccm/ccm.env, s právy 600. Nastavte:

- APP_URL na veřejnou HTTPS adresu a TRUSTED_PROXIES na IP/CIDR vaší reverzní proxy (oddělené čárkami). Proxy musí předávat Host a X-Forwarded-Proto. Hvězdičku použijte jen při síťově vynuceném přístupu výhradně přes proxy.
- APP_KEY na stabilní Laravel klíč (generování níže). Klíč uchovejte i při dalších deployích.
- DB_URL na připojovací řetězec, například `mysql://ccm:URL_ENCODED_PASSWORD@db.internal:3306/ccm?charset=utf8mb4`.
- DB_CONNECTION=mariadb, SESSION_SECURE_COOKIE=true pro HTTPS.

Heslo a uživatelské jméno v URL musí mít percent-encoding speciálních znaků. Soubor se načítá jako env konfigurace, nikoli spouští jako shell skript. Alternativně odstraňte DB_URL a nastavte DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME a DB_PASSWORD, které podporuje Laravel konfigurace.

DB na hostiteli kontejneru je dostupná jako host.docker.internal díky extra_hosts, pokud naslouchá na dostupném rozhraní a firewall spojení povoluje. localhost uvnitř kontejneru není hostitel. Pro vzdálený server používejte privátní síť; TLS lze zapnout nastavením MYSQL_ATTR_SSL_CA a read-only mountem CA souboru přes lokální Compose override. Image neobsahuje žádný skutečný připojovací údaj.

## První spuštění z checkoutu

Požadavky: Git, Docker Engine, Docker Compose v2 a dostupná MariaDB. Příkazy níže spouštějte v kořeni repozitáře. Příklad předpokládá env soubor /opt/ccm/ccm.env.

```sh
export CCM_ENV_FILE=/opt/ccm/ccm.env
docker compose build
docker compose run --rm --no-deps app php artisan key:generate --show
```

Vypsaný klíč vložte jako APP_KEY do chráněného env souboru. Příkaz ho nevkládá do image. Poté:

```sh
docker compose run --rm --no-deps app php artisan ccm:check-db
docker compose run --rm --no-deps app php artisan migrate --force
docker compose up -d --wait
docker compose exec app php artisan ccm:user vas@email.cz --name="Správce"
```

Heslo se zadává interaktivně skrytě, minimálně 12 znaků; příkaz nevytváří výchozí veřejný účet. Web běží na 127.0.0.1:8080. Před něj nastavte HTTPS reverse proxy, bez cache soukromých odpovědí. Pro místní HTTP vývoj lze přepnout SESSION_SECURE_COOKIE=false; v produkci ponechte true. Identifikátory požadavků pro zadání tankování a výdajů fungují i přes HTTP: pokud prohlížeč neposkytuje crypto.randomUUID, použije se UUID v4 vytvořené pomocí crypto.getRandomValues. Pokud proxy běží v jiném kontejneru, nastavte společnou síť místo předpokladu, že její localhost je aplikační hostitel.

## Nasazení konkrétního release z GitHubu

```sh
bash deploy/deploy.sh COMMIT_NEBO_TAG /opt/ccm/ccm.env /opt/ccm/releases
```

Volitelné CCM_MIGRATION_ENV_FILE určuje samostatný chráněný env soubor s DB účtem pro migrace (DDL); běžící aplikace dál používá původní runtime soubor s omezeným účtem. Bez této volby se stejný účet používá i pro migrace a musí mít potřebná DDL oprávnění.

Skript stáhne požadovaný commit, sestaví image označený SHA, ověří DB, provede migrace a spustí kontejner. U soukromého repozitáře nejprve nastavte serverový Git přístup. Pro SSH deploy key lze při prvním nasazení nastavit CCM_REPOSITORY_URL=git@github.com:MartinDejmal/ccm.git; HTTPS používá běžně nakonfigurované Git přihlašování. Pro již existující instalaci vyžaduje ověřenou zálohu a výslovné `CCM_BACKUP_CONFIRMED=1` v prostředí deploy skriptu. Zálohu sám neprovádí a netvrdí, že její obnovitelnost ověřil.

Při aktualizaci zapne údržbu a po kontrole připravenosti ji vypne. Sdílený volume zachová storage i stav údržby. Při chybě může aplikace zůstat v údržbě; nejprve zkontrolujte migrace a logy a teprve poté spusťte artisan up. Skript automaticky nevrací databázi ani neprovádí upgrade MariaDB. Návrat předchozího image je možný jen při kompatibilním schématu. První verze skriptu počítá s jedním Compose projektem ccm na hostiteli a krátkou odstávkou.

/ready ověřuje spojení a dokončení úvodních migrací, /up živost Laravelu. Logy jsou dostupné přes docker compose logs. Zálohujte databázi i volume ccm_storage. Neprovádějte docker compose down -v, pokud chcete data storage zachovat.

## Vývoj a ověření

PHP 8.4 s pdo_mysql, intl, mbstring, zip, gd (s WebP), exif, Composer 2, Node.js 24. Po instalaci závislostí: npm run test:client, npm run build a vendor/bin/phpunit. Pro integrační testy použijte **výhradně samostatnou testovací DB**, protože RefreshDatabase databázi obnovuje. DB_CONNECTION, DB_HOST, DB_DATABASE, DB_USERNAME a DB_PASSWORD v prostředí přepíší výchozí in-memory SQLite konfiguraci PHPUnit. CI běží proti MariaDB 10.5.28.

API je nyní /api/v1, zabezpečené webovou session a CSRF. Nativní tokeny a kompletní OpenAPI kontrakt přijdou v dalším rozšíření; jejich připravenost není tvrzením, že je nyní mobilní klient přihlásitelný.

## Obrázky vozidel a trvalé úložiště

Docker image obsahuje GD a EXIF a nastavuje upload_max_filesize=6M, post_max_size=8M a memory_limit=256M. Aplikační limit obrázku je 5 MiB; reverse proxy musí povolit alespoň 8 MiB těla požadavku. Obrázky se ukládají na lokální neveřejný disk storage/app/private/vehicle-avatars v existujícím volume ccm_storage. Nepotřebují storage:link a nejsou veřejným adresářem webu. Při nahrazení nebo odstranění se starý soubor maže; archivace ho zachovává. Záloha a obnova musí zahrnovat DB i tento volume. Neměňte FILESYSTEM_DISK kvůli avatarům; pro ně se výslovně používá disk local. Více aplikačních instancí bude vyžadovat společné úložiště.
