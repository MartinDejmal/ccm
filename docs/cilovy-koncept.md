# CCM — cílový koncept aplikace

Stav: návrh pro zahájení vývoje, verze 1.0, 20. 10. 2026. Repozitář: MartinDejmal/ccm.

Technické řešení nově doplňuje [technická koncepce](technicka-koncepce.md): externí MariaDB, Laravel API, responzivní React web a Docker nasazení. Tento dokument zůstává funkčním a datovým základem.

Dokument definuje funkce, logický datový model, počáteční číselníky a import SpritMonitor.de. Neobsahuje implementaci ani výběr aplikačních technologií. Datové typy respektují preferenci MariaDB 10+; konkrétní podporovaná minor verze a způsob vynucování pravidel se určí před implementací. Přiložené CSV jsou datové podklady, nikoli instrukce pro aplikaci nebo vývoj.

## 1. Účel a rozsah

CCM (Car Cost Management) eviduje provoz jednoho či více vozidel. Uživatel získá přehled o nákupu paliva a energie, skutečné spotřebě a výdajích na údržbu, servis, pojištění, poplatky, vybavení a pořízení auta. Záznamy zadává ručně nebo importuje z CSV SpritMonitor.de. Každé vozidlo má vlastní historii a nastavení jednotek.

První verze zahrne správu vozidel, deník tankování/nabíjení, deník ostatních nákladů, opravy a vyřazení záznamů, import s náhledem a reporty podle období, vozidla, kategorie a měny. Uchová interval opakování výdaje a zobrazí další plánovaný termín. Plánovaná položka není skutečně zaplacený výdaj.

Pozdější rozšíření: sdílení vozidel, přílohy účtenek, notifikace, servisní plány podle kilometrů, automatické kurzy, přímá synchronizace se SpritMonitorem a kniha jízd. Nejsou podmínkou první verze. Import nesmí zaměňovat CSV Trip s evidencí jednotlivých jízd.

## 2. Struktura aplikace a hlavní postupy

Podrobné scénáře jsou v [případech použití programu UC-01 až UC-07](program/pripady-pouziti.md).

- **Přehled:** výběr vozidla a období, náklady po měnách, spotřeba podle média, náklady na vzdálenost, grafy a upozornění na neúplná data.
- **Vozidla:** název, značka/model, volitelně VIN a registrační značka, jednotka tachometru, počáteční stav, datum pořízení, výchozí měna, používaná paliva/energie, archivace.
- **Tankování a nabíjení:** datum, tachometr, množství a jednotka, médium, cena a měna, typ tankování, podmínky jízdy, palubní údaje, čerpací místo a poznámka.
- **Ostatní náklady:** datum, volitelný tachometr a vzdálenost, kategorie, částka s měnou, poznámka, interval opakování.
- **Historie:** společná časová osa obou druhů záznamů; několik událostí ve stejný den či na stejném tachometru je přípustných.
- **Import:** výběr vozidla a typu exportu → rozpoznání formátu a jednotek → náhled → řešení chyb a duplicit → potvrzení → souhrn a dohledatelná dávka.
- **Statistiky:** odděleně provozní výdaje, pořízení, financování a příjmy/vratky. Filtry musí ukázat, co součet obsahuje.

Předpoklad první verze: vozidlo má jednoho vlastníka. Uživatelské účty jsou logickou hranicí dat; způsob přihlašování tento koncept neurčuje. Každé čtení či změna dat musí ověřit vlastnictví vozidla. Archivace vozidla uchová historii.

## 3. Zjištění ze skutečných příloh

| Vlastnost | Tankování | Náklady |
|---|---|---|
| Soubor | 1724509_fuelings.csv | 1724509_costs (1).csv |
| Počet datových záznamů | 17 | 24 |
| Počet sloupců | 22 | 7 |
| Období | 22. 4.–16. 10. 2026 | 22. 4.–16. 10. 2026 |
| Součet cen | 35 016,60 CZK a 236,43 EUR | 560 093,94 CZK |

Oba soubory jsou UTF-8 se středníkem a desetinnou čárkou. Data jsou sestupně podle data. Náklady mají záhlaví `Date;Odometer;Cost type;Total price;Currency;Note;Repetition`, nemají Trip a místo číselných typů používají anglické názvy. Obsahují víceřádkové poznámky a uvozovku escapovanou zpětným lomítkem. Počet fyzických řádků proto není počet záznamů.

Tankování používá `Trip`, `Type` a `Fuel`, nikoli pouze slovní názvy z obecného popisu. První tankování nemá Trip, Consumption ani souřadnice. Nuly BC údajů zřejmě zastupují nezadané údaje; tato interpretace je pravidlem importního profilu s viditelným upozorněním. Země jsou CZ, HR a SLO; SLO se mapuje na SI se zachováním originálu. Náklady obsahují vratku −5 065 CZK v kategorii Insurance a pojištění s opakováním 12 měsíců.

Stav tachometru nemusí mezi servisními záznamy chronologicky růst: například oprava 29. 7. má 406 726, zatímco 21. 7. má 407 209. Jde o varování k ověření, nikoli důvod zahodit celý import. Trip se mírně liší od rozdílu zaokrouhlených tachometrů. Poznámky o AdBlue nesmí automaticky vytvářet další nákup ani navyšovat cenu či množství.

## 4. Datový model a společné konvence

Částky a měřené hodnoty používají přesné desetinné typy, nikdy FLOAT pro peníze. PK je interní BIGINT UNSIGNED. FK má stejný typ jako odkazovaný klíč, u číselníků může být SMALLINT UNSIGNED. `?` znamená NULL povoleno; bez `?` je pole povinné. FK jsou výslovně označené. Datum události je DATE bez časové zóny; technické časy DATETIME(6) v UTC. Text je Unicode (utf8mb4). Nula je hodnota, NULL znamená nezjištěno; výjimky zdrojových nul jsou popsány v importním profilu.

Všechny hlavní entity mají `id`, `created_at`, `updated_at`. Vozidla a události dále `deleted_at?` pro vratné vyřazení. Součty vyřazené záznamy nezahrnují. Fyzické smazání vlastníka/vozidla se neprovádí kaskádově bez samostatné politiky odstranění dat. Číselníky se při použití nemažou; lze je deaktivovat.

### 4.1 Uživatel, vozidlo a používaná média

| Tabulka | Pole nad rámec společných |
|---|---|
| `app_user` | `display_name VARCHAR(160)`, `preferred_currency_code CHAR(3)` FK currency |
| `vehicle` | `owner_id` FK app_user, `name VARCHAR(160)`, `make VARCHAR(100)?`, `model VARCHAR(100)?`, `vin VARCHAR(32)?`, `registration_plate VARCHAR(32)?`, `distance_unit_code VARCHAR(10)` FK unit, `default_currency_code CHAR(3)` FK currency, `initial_odometer DECIMAL(14,3)?`, `acquired_on DATE?`, `archived_at DATETIME(6)?` |
| `vehicle_energy_stream` | `vehicle_id` FK vehicle, `name VARCHAR(100)`, `medium_group_code VARCHAR(20)` FK medium_group, `quantity_unit_code VARCHAR(10)` FK unit, `consumption_method_code VARCHAR(24)` FK consumption_method, `is_active BOOLEAN` |

Proud média odděluje například benzinovou nádrž, LPG, elektrickou energii a AdBlue. Prémiová a běžná nafta mohou patřit do stejné nádrže. Stejné vozidlo může mít více proudů; litry, kg a kWh nelze sčítat. Přípustnost paliva pro skupinu a jednotku ověřuje tabulka `fuel_unit` (fuel_sort_id FK, unit_code FK; složený PK) a skupina paliva. VIN ani registrační značka nejsou globálně povinné unikátní identifikátory vlastnictví.

### 4.2 Společný finanční záznam

`vehicle_event` je společný základ tankování i ostatních nákladů. Každá aktivní událost má právě jeden odpovídající detail podle event_kind; detail nemůže existovat samostatně. Vytvoření, změna i vyřazení základu a detailu je atomické.

| Pole | Typ / význam |
|---|---|
| `vehicle_id` | FK vehicle |
| `event_kind_code` | FK event_kind: fueling nebo expense |
| `occurred_on` | DATE |
| `day_sequence` | INT UNSIGNED; pořadí v rámci dne, ne domyšlený čas |
| `odometer` | DECIMAL(14,3)? v jednotce vozidla |
| `amount` | DECIMAL(18,4); podepsaný peněžní tok, kladný výdaj, záporná vratka/příjem |
| `currency_code` | CHAR(3) FK currency |
| `note` | TEXT? |
| `created_by` | FK app_user |
| `revision` | INT UNSIGNED; ochrana proti přepsání souběžnou úpravou |

Cena tankování je zde pouze jednou. Náklad na palivo se nepřidává podruhé do expense. Nulová cena je přípustná, neznámá cena v první verzi vyžaduje vyřešení při importu. Záporná částka je možná i v jiné kategorii než Refund; znamená finanční korekci dané kategorie. Samotná kategorie nikdy automaticky neobrací znaménko.

### 4.3 Detail tankování / nabíjení

`fueling` má PK `event_id` současně FK vehicle_event. Pole:

| Pole | Typ / význam |
|---|---|
| `energy_stream_id` | FK vehicle_energy_stream stejného vozidla jako událost |
| `fuel_sort_id` | FK fuel_sort |
| `fueling_type_id` | FK fueling_type; kód 0 je platná hodnota číselníku |
| `quantity` | DECIMAL(14,4), kladná |
| `quantity_unit_code` | FK unit; musí odpovídat proudu média a fuel_unit |
| `reported_trip` | DECIMAL(14,3)?; importované Trip, nikoli dopočtená vzdálenost |
| `break_before` | BOOLEAN DEFAULT false; explicitně chybějící historie před tankováním, interní údaj mimo CSV |
| `reported_consumption` | DECIMAL(14,4)?; původní spotřeba služby |
| `bc_consumption` | DECIMAL(14,4)?; palubní spotřeba |
| `bc_quantity` | DECIMAL(14,4)? v jednotce množství |
| `bc_speed` | DECIMAL(10,3)? v jednotce rychlosti |
| `consumption_distance_unit_code` | FK unit; základ spotřeby je 100 těchto jednotek vzdálenosti |
| `speed_unit_code` | FK unit |
| `tire_type_id` | FK tire_type? |
| `driving_style_id` | FK driving_style? |
| `company` | VARCHAR(255)? |
| `country_code` | CHAR(2) FK country? |
| `area`, `location` | VARCHAR(255)? každé |
| `latitude`, `longitude` | DECIMAL(9,6)?, DECIMAL(10,6)? |

`fueling_road` má složený PK (`event_id` FK fueling, `road_type_id` FK road_type). Prázdná množina znamená neuvedené podmínky; žádná smyšlená kategorie „žádná silnice“. Firma a místo jsou snímek údajů konkrétní návštěvy, nikoli povinně sdílený objekt čerpací stanice.

### 4.4 Ostatní náklady a opakování

`expense`: PK `event_id` FK vehicle_event, `expense_type_id` FK expense_type, `reported_trip DECIMAL(14,3)?`, `repetition_months` FK repetition_interval (výchozí 0). Importovaný interval uchováváme přímo u historického výdaje.

`recurring_expense_plan`: id a technické časy, `vehicle_id` FK vehicle, `source_event_id` FK expense UNIQUE, `interval_months` FK repetition_interval (nenulový), `anchor_date DATE`, `next_due_on DATE`, `ended_on DATE?`, `is_active BOOLEAN`. Vytvoření plánu z importu vyžaduje samostatnou volbu v náhledu, výchozí stav je nevytvářet. Historické záznamy s intervalem tak automaticky nevytvářejí více aktivních plánů. Plán připomíná termín, negeneruje zaplacené výdaje. Termíny se odvozují od kotevního data; 31. den připadne na poslední den kratšího měsíce a další měsíc se znovu použije původní kotva. Částka příští platby je jen odhad podle zdrojového záznamu.

### 4.5 Import a původ údajů

| Tabulka | Pole / význam |
|---|---|
| `import_batch` | id, technické časy, `vehicle_id` FK, `created_by` FK app_user, `source_code` FK import_source, `dataset_kind_code` FK event_kind, `source_vehicle_reference VARCHAR(100)?`, `original_filename VARCHAR(255)`, `file_sha256 CHAR(64)`, `profile_version VARCHAR(30)`, `encoding VARCHAR(30)`, `delimiter CHAR(1)`, `decimal_separator CHAR(1)`, `distance_unit_code` FK unit, `quantity_unit_code` FK unit, `status_code` FK import_batch_status, `committed_at DATETIME(6)?` |
| `import_row` | id, `batch_id` FK import_batch, `record_number INT UNSIGNED`, `physical_line_start INT UNSIGNED`, `raw_record LONGTEXT`, `normalized_payload LONGTEXT?`, `fingerprint CHAR(64)?`, `status_code` FK import_row_status, `event_id` FK vehicle_event?, `duplicate_of_row_id` FK import_row?, `resolution_note TEXT?` |
| `import_issue` | id, `row_id` FK import_row, `severity_code` FK issue_severity, `field_name VARCHAR(100)?`, `issue_code VARCHAR(80)`, `message TEXT` |
| `event_revision` | id, `event_id` FK vehicle_event, `actor_id` FK app_user, `occurred_at DATETIME(6)`, `action_code` FK revision_action, `before_snapshot LONGTEXT?`, `after_snapshot LONGTEXT?`, `import_row_id` FK import_row? |

Snímky/payload jsou dokumentované serializované hodnoty; tento koncept nevyžaduje funkce nativního JSON typu. Raw záznam zahrnuje původní hodnoty všech sloupců i neznámá pole. Identifikátor 1724509 z názvu je pouze nabídnutá externí reference; přiřazení vozidla potvrzuje uživatel. Nejde o PK aplikace. CSV nelze považovat za globálně identifikovaný záznam služby.

## 5. Klíče, integrita a indexy

- PK a FK ve všech uvedených vazbách; mazání odkazovaných číselníků a historie RESTRICT. Detail sdílí PK s událostí. Kontrola právě jednoho detailu a shody druhu, vlastníka a vozidla je povinná transakční doménová podmínka.
- UNIQUE `import_row(batch_id, record_number)`, `fueling_road(event_id, road_type_id)`, kódy číselníků a jejich SpritMonitor ID. Číselné zdrojové ID je přímo klíčem pouze u převzatých číselníků.
- Indexy `vehicle(owner_id, archived_at)`, `vehicle_event(vehicle_id, occurred_on, day_sequence, id)`, `vehicle_event(vehicle_id, odometer)`, `vehicle_event(vehicle_id, currency_code, occurred_on)`, `expense(expense_type_id, event_id)`, `fueling(energy_stream_id, event_id)`, `import_batch(vehicle_id, source_code, dataset_kind_code, file_sha256)`, `import_row(fingerprint)`, `event_revision(event_id, occurred_at)`, `recurring_expense_plan(is_active, next_due_on)`.
- File hash ani fingerprint nejsou globálně unikátní: je nutné umožnit opakovaný náhled i skutečně shodné události. Pořadí v rámci dne se nesmí používat jako obchodní identita.
- Tachometr a vzdálenost jsou nezáporné, množství kladné; souřadnice v rozsazích −90 až 90 a −180 až 180, buď obě nebo žádná. Neúplná dvojice je chyba k opravě. Spotřeba, BC údaje a rychlost jsou nezáporné, nula je jinak přípustná.
- Indexy jsou návrhem pro běžné přehledy, nikoli závazkem vytvořit každý index bez ověření dotazů. Vynucení CHECK závisí na konkrétní MariaDB 10.x; pravidla se musí ověřovat i při zápisu, nesmí spoléhat na ignorované deklarace starších verzí.

## 6. Počáteční hodnoty číselníků

Číselníky mají stabilní klíč, český název, případný přesný zdrojový název, pořadí a `is_active`. Nulový zdrojový kód není NULL. Neznámé hodnoty se automaticky nepřepisují na „ostatní“.

### Typ tankování (`fueling_type`)

| ID SpritMonitor | Název |
|---|---|
| 0 | Neplatné tankování pro výpočet spotřeby |
| 1 | Plné tankování |
| 2 | Částečné tankování |
| 3 | První tankování |

### Pneumatiky, styl a silnice

| Číselník | ID → český název |
|---|---|
| tire_type | 1 → letní; 2 → zimní; 3 → celoroční |
| driving_style | 1 → klidný; 2 → běžný; 3 → rychlý |
| road_type | 2 → dálnice; 4 → město; 8 → mimo město |

Roads je bitová maska pouze při importu: platné hodnoty 0, 2, 4, 6, 8, 10, 12, 14. Hodnota 10 se rozloží na 2 a 8. Neznámé bity jsou chyba.

### Paliva a energie (`fuel_sort`)

ID jsou přesně zdrojová, mezery v posloupnosti se nedoplňují. Jednotka v tabulce je výchozí pro nový profil; skutečnou jednotku exportu musí potvrdit importní kontext.

| ID | Zdrojový název | Český název | Skupina | Výchozí jednotka |
|---|---|---|---|---|
| 1 | Diesel | Nafta | diesel | l |
| 2 | Biodiesel | Bionafta | diesel | l |
| 3 | Vegetable oil | Rostlinný olej | diesel | l |
| 4 | Premium Diesel | Prémiová nafta | diesel | l |
| 6 | Normal gasoline | Benzin Normal | gasoline | l |
| 7 | Super gasoline | Benzin Super | gasoline | l |
| 8 | SuperPlus gasoline | Benzin SuperPlus | gasoline | l |
| 9 | Premium Gasoline 100 | Prémiový benzin 100 | gasoline | l |
| 12 | LPG | LPG | lpg | l |
| 13 | CNG H | CNG H | cng | kg |
| 14 | CNG L | CNG L | cng | kg |
| 15 | Bio-alcohol | Bioalkohol | alcohol | l |
| 16 | Two-stroke | Dvoutaktní směs | two_stroke | l |
| 18 | Premium Gasoline 95 | Prémiový benzin 95 | gasoline | l |
| 19 | Electricity | Elektřina | electricity | kWh |
| 20 | E10 | Benzin E10 | gasoline | l |
| 21 | AdBlue | AdBlue | additive | l |
| 22 | Premium Gasoline 100+ | Prémiový benzin 100+ | gasoline | l |
| 23 | Hydrogen | Vodík | hydrogen | kg |
| 24 | Green electricity | Zelená elektřina | electricity | kWh |
| 25 | GTL Diesel | GTL nafta | diesel | l |
| 26 | HVO100 | HVO100 | diesel | l |

`medium_group` obsahuje všechny skupiny z tabulky. `fuel_sort` odkazuje na skupinu; `fuel_unit` se iniciálně naplní dvojicemi ID a výchozí jednotka z tabulky. Další jednotky se povolí až s definovanou interpretací zdroje. Skupina additive není trakční palivo; AdBlue se zahrne do nákladů, nikoli do spotřeby nafty.

### Kategorie výdajů (`expense_type`)

| ID | Přesný zdrojový název / importní alias | Český název | Výchozí skupina reportu |
|---|---|---|---|
| 1 | Maintenance | Údržba | operating |
| 2 | Repair | Oprava | operating |
| 3 | Change tires | Výměna pneumatik | operating |
| 4 | Change oil | Výměna oleje | operating |
| 5 | Insurance | Pojištění | operating |
| 6 | Tax | Daň | operating |
| 7 | Supervisory board | Technická kontrola — pracovní překlad | operating |
| 8 | Tuning | Úpravy vozidla | operating |
| 9 | Accessories | Příslušenství | operating |
| 10 | Purchase price | Pořizovací cena | acquisition |
| 11 | Miscellaneous | Ostatní | operating |
| 12 | Care | Péče o vozidlo | operating |
| 13 | Payment | Platba | financing |
| 14 | Registration | Registrace | acquisition |
| 15 | Financing | Financování | financing |
| 16 | Refund | Vratka | adjustment |
| 17 | Fine | Pokuta | operating |
| 18 | Parking tax | Parkovné | operating |
| 19 | Toll | Mýtné a dálniční poplatky | operating |
| 20 | Spare parts | Náhradní díly | operating |
| 21 | Basic charging fee | Základní poplatek za nabíjení | operating |
| 22 | Digital services | Digitální služby | operating |
| 23 | GHG bonus | Bonus za snížení emisí GHG | adjustment |

`expense_type.report_group_code` je FK na `report_group`: operating = provoz, acquisition = pořízení, financing = financování, adjustment = korekce a příjmy. Jde o výchozí reportingové členění, nikoli právní či účetní klasifikaci. Význam kódu 7 a neurčitého Payment se před použitím v uživatelském rozhraní ověří proti aktuální terminologii služby; zdrojový název a ID se zachovají. Refund/bonus v kladné zdrojové částce vyvolá varování a volbu interpretace, nikoli tichou změnu znaménka. Celkový peněžní tok může obsahovat pořízení i splátky, ale nesmí být vydáván za ekonomické celkové náklady bez upozornění na možné dvojí započtení jistiny.

### Jednotky, měny, země a další číselníky

| Číselník | Úplná počáteční sada / pravidlo |
|---|---|
| unit | km = kilometr, mi = míle (distance); l = litr, kg = kilogram, kWh = kilowatthodina (quantity); km/h, mi/h (speed) |
| currency | CZK = česká koruna (2 desetinná místa), EUR = euro (2), USD = americký dolar (2); klíč CHAR(3), pouze potvrzené ISO 4217 kódy, před dalším importem lze doplnit další měny |
| country | CZ = Česko, HR = Chorvatsko, SI = Slovinsko, DE = Německo, AT = Rakousko, SK = Slovensko, PL = Polsko; další země se doplní podle ISO 3166-1 alpha-2 |
| country_alias | zdroj spritmonitor + SLO → SI; standardní kódy se mapují přímo; raw hodnota se vždy uchová |
| repetition_interval | 0 = bez opakování; 1 = měsíčně; 3 = čtvrtletně; 6 = pololetně; 12 = ročně; 24 = každé dva roky; 36 = každé tři roky; PK je počet měsíců |
| consumption_method | full_to_full = plná–plná; delivery_only = evidence dodávek bez odhadu skutečné spotřeby |
| event_kind | fueling = tankování/nabíjení; expense = ostatní náklad |
| import_source | spritmonitor = SpritMonitor.de |
| import_batch_status | uploaded, validating, ready, committed, failed, cancelled, reverted |
| import_row_status | pending, valid, warning, error, imported, skipped, reverted |
| issue_severity | warning, error |
| revision_action | create, update, soft_delete, restore |

Zdroj ručního záznamu plyne z absence importní vazby; import_source se pro ruční zadávání nepoužívá. `country_alias` má složený PK (source_code, alias), FK na country. Měny a země jsou úmyslně rozšiřitelné; výše je plná počáteční sada tohoto návrhu, ne tvrzení o úplnosti světových standardů.

## 7. Importní kontrakt SpritMonitor.de

Uživatelská specifikace obou souborů je vedena v [dokumentaci programu](program/csv-import-export.md). Základní struktura dodaných CSV bude společná pro import i export CCM; dokumentace uvádí i hranice zpětného přenosu a rozdíly profilů SpritMonitor/CCM.

### 7.1 Rozpoznání a validace

Import pracuje se skutečnými CSV záznamy, nikoli se samostatnými řádky textu. Profil vzorků: UTF-8 s volitelným BOM, středník, desetinná čárka, obalovací dvojité uvozovky, víceřádková pole a zpětné lomítko pro escapování uvozovek. Varianta se zdvojenými uvozovkami může být samostatně rozpoznaným profilem; nesmí se neřízeně přepisovat obsah všech poznámek. Náhled uvede zvolený profil. Chybný počet sloupců či neuzavřené pole je chyba, ne důvod odhadovat posun buněk.

Datum se striktně převede z DD.MM.YYYY, včetně kalendářní platnosti. Desetinnou tečku lze přijmout v jiném explicitně zvoleném profilu. Oddělovače tisíců nejsou povolené, smíšená či nejednoznačná čísla vyžadují opravu. Prázdné volitelné buňky → NULL. Ořízne se okrajová mezera kódů, nikoli obsah poznámek. Číslo mimo rozsah či přesnost se nezaokrouhlí bez upozornění.

Uživatel před potvrzením určí jednotku vzdálenosti a množství, protože v CSV nejsou. Pro přílohy se nabídne km, l, l/100 km a km/h. Výběr paliva sám není důkaz jednotky exportu. Při smíšených médiích se určí jednotky pro jednotlivé proudy. Jednotky uložené historie se nemění pouhou změnou nastavení vozidla.

Povinné hlavičky tankování: Date, Quantity, Total price, Currency, Type/Fueling type, Fuel/Fuel sort. Odometer je nutný pro vlastní výpočet plná–plná, jeho absence blokuje výpočet a vyvolá varování; ostatní nepovinné sloupce mohou chybět. Náklady vyžadují Date, Cost type/Type, Total price, Currency; chybějící Repetition znamená 0 s informací v náhledu. Neznámé sloupce se zachovají a oznámí. Opakované nebo nejednoznačné hlavičky jsou chyba.

### 7.2 Mapování polí

| CSV pole / alias | Cíl |
|---|---|
| Date | vehicle_event.occurred_on |
| Odometer | vehicle_event.odometer |
| Total price, Currency, Note | vehicle_event.amount, currency_code, note |
| Trip / Distance (tankování) | fueling.reported_trip |
| Quantity | fueling.quantity |
| Type / Fueling type (tankování) | fueling.fueling_type_id |
| Fuel / Fuel sort | fueling.fuel_sort_id |
| Tires, Driving style | fueling.tire_type_id, driving_style_id |
| Roads | rozklad masky do fueling_road |
| Consumption | fueling.reported_consumption |
| BC-Consumption / BC-consumption | fueling.bc_consumption |
| BC-Quantity / BC-quantity | fueling.bc_quantity |
| BC-Speed / BC-speed | fueling.bc_speed |
| Company, Country, Area, Location | fueling.company, country_code, area, location |
| Latitude, Longitude | fueling.latitude, longitude |
| Cost type / Type (náklady) | expense.expense_type_id; číslo 1–23 nebo zdrojový název z číselníku |
| Trip (náklady, pokud přítomen) | expense.reported_trip |
| Repetition | expense.repetition_months |

Aliasování hlaviček je bez ohledu na velikost písmen s normalizací okrajových mezer; nepoužívá se neurčitá podobnost názvů. Anglické názvy kategorií se mapují celým normalizovaným názvem. Neznámé palivo, kategorie, měna, země či interval vyžadují vyřešení v náhledu; číselníky se z libovolného textu samy nezakládají.

Profil příloh převádí nulové BC-Consumption, BC-Quantity a BC-Speed na NULL s agregovaným upozorněním a zachováním raw nul. Ostatní nuly se nepřevádějí plošně: Roads 0 je neuvedeno, Type 0 je neplatné tankování pro spotřebu, Repetition 0 znamená bez opakování a souřadnice 0 jsou platné souřadnice.

### 7.3 Duplicity, potvrzení a návrat importu

Přesně stejný soubor se v rámci stejného vlastníka, vozidla, zdroje a druhu nabídne jako již importovaný. Ochrana platí pouze vůči aktivním dokončeným importům; zrušená dávka nebrání novému importu. Samotná shoda hashe nesmí přiřadit data jinému vozidlu.

Fingerprint je otisk normalizovaného celého obchodního obsahu, včetně měny, jednotek a poznámky, bez pořadí řádku a technických časů. Hledá se pouze v rámci vozidla a druhu. Shodné řádky v dávce, shody s historií i podobné ruční záznamy se ukážou jako kandidáti. Uživatel může přeskočit nebo výslovně importovat i shodný záznam. Shodný den a tachometr s odlišnou cenou není automatická aktualizace. Bez stabilního zdrojového ID se import nepokouší přepsat existující událost.

Výchozí potvrzení vyžaduje vyřešit všechny chyby; každý řádek musí být připraven k importu nebo výslovně přeskočen. Zápis vybraných řádků, detailů, vazeb a stavu dávky proběhne atomicky. Opakované potvrzení stejné dávky nesmí vytvořit další události ani při souběžných požadavcích. Náhled sám obchodní historii nemění. Souhrn uvede počty importovaných, přeskočených a varovných záznamů a součty po měnách.

Vrácení dávky vyřadí pouze události, které tato dávka vytvořila. Události později ručně změněné se uvedou jako konflikty a nevyřadí bez výslovného řešení. Událost převzatá jako dřívější duplicita se nemaže. Uchová se audit a stav reverted; reporty se přepočítají. Raw data a audit podléhají stejnému vlastnictví a budoucí retenční politice jako ostatní historie. Obsah poznámek se vykresluje jako text, nikdy jako instrukce či aktivní obsah.

## 8. Výpočty a význam reportů

### 8.1 Spotřeba

Importovaná Consumption a BC údaje jsou původní měření, nikoli autoritativní přepis vlastního výpočtu. UI je odliší od vypočtené spotřeby. Vlastní výpočet probíhá chronologicky pro jeden proud média a jednu jednotku, s vyřešeným pořadím událostí ve stejném dni.

Pro plná–plná: předchozí potvrzené plné tankování A stanoví výchozí stav. Množství A se nezapočítává. Sečtou se množství všech následujících částečných tankování a uzavíracího plného tankování B; vzdálenost je tachometr B minus tachometr A. Spotřeba = 100 × součet množství / vzdálenost. Průměr více úplných intervalů je 100 × součet jejich množství / součet jejich vzdáleností, nikoli aritmetický průměr řádkových spotřeb.

Typ 2 sám interval neuzavírá. Typ 3 zahajuje novou historii, nemá vlastní vypočtenou spotřebu a bez zvláštního potvrzení plné nádrže není plnou kotvou. První následné plné tankování teprve založí kotvu. Typ 0 se finančně eviduje, ale přeruší výpočetní řetězec: nesmí se pouze vynechat jeho množství a následně spočítat zkreslený interval. Totéž platí pro chybějící tankování, reset tachometru nebo nesrovnatelnou jednotku; další potvrzené plné tankování zakládá novou kotvu.

Při nulové či záporné vzdálenosti, chybějícím tachometru nebo nejasném pořadí se výsledek nezobrazí jako platný. Trip lze použít pro samostatně označený orientační přehled dodávky na deklarovanou vzdálenost, nikoli jako tichou náhradu plná–plná. Rozdíl Trip a tachometru je varování s tolerancí odpovídající přesnosti zdroje; obě původní hodnoty zůstanou zachovány.

Elektřina má výchozí metodu delivery_only: nakoupené kWh na vzdálenost nejsou automaticky skutečná spotřeba baterie (neznámý stav nabití, ztráty). Takový poměr je označen „dodaná energie na vzdálenost“ a vyžaduje vymezené období a spolehlivé odečty. Kombinované pohony se zobrazují po jednotlivých médiích, bez sčítání l a kWh. AdBlue je oddělený provozní materiál. Ani cena z poznámky, ani zmíněná sleva se automaticky nepřičítá: rozhoduje Total price.

### 8.2 Náklady, měny a vzdálenost

Celkové výdaje jsou součet podepsaných částek aktivních událostí v dané měně; vratky součet snižují ve své kategorii. Kategorie Refund se kvůli znaménku znovu neodečítá. Zobrazí se hrubé kladné výdaje, záporné korekce a čistý součet. Pořízení a financování jsou samostatné přepínače a subtotaly.

CZK, EUR ani USD se přímo nesčítají. První verze vykazuje výsledky po měnách. Výchozí měna vozidla předvyplňuje nové záznamy; nemění historické ceny. Přepočty kurzem nejsou součástí první verze, pro budoucí přepočet bude nutné uchovat kurz, datum, zdroj a cílovou měnu.

Náklady/km jsou peněžní výdaje vybraného období dělené prokazatelnou vzdáleností tohoto období. Pokud chybějí odečty na hranicích, report ukáže skutečné pokryté datum od–do a vzdálenost mezi dostupnými odečty, nebo metriku neposkytne. Nesmí použít celoživotní tachometr jako ujetou vzdálenost za dobu vlastnictví ani přičítat servisní Trip k tankovacímu Trip. Každá měna má vlastní metriku. Náklady na nakoupené palivo nejsou přesně náklady na palivo spotřebované v daném měsíci.

## 9. Kritéria připravenosti první verze

1. Ruční zadání a úpravy obou druhů záznamů zachovají měnu, jednotky, poznámku a historii změn; data cizího vlastníka nejsou přístupná.
2. Přiložené soubory se načtou jako 17 tankování a 24 nákladů; zachovají diakritiku, víceřádkové poznámky, uvozovku, zápornou vratku a interval 12. Import správně rozliší textové kategorie a číselný Type tankování.
3. Kontrolní součty odpovídají kapitole 3, bez převodu EUR na CZK a bez dvojí evidence tankování. Základní součet ostatních nákladů obsahuje i pořizovací cenu 280 000 CZK; report ji umí oddělit.
4. Import zachová prázdná pole jako NULL, kód 3 jako první tankování, Roads 10 jako dvě vazby a SLO jako SI s dohledatelným originálem. Neznámé kódy a chybné hodnoty nezmizí beze stopy.
5. Stejná dávka se nepotvrdí dvakrát; další nahrání stejného souboru nabídne duplicity. Skutečné dvě shodné platby lze po výslovném rozhodnutí zachovat.
6. Spotřeba respektuje první, částečné a neplatné tankování, chybějící historii a oddělená média. Průměr je vážený vzdáleností. Nesrovnalost servisního tachometru vyvolá varování, ne tichou opravu.
7. Opakování 12 měsíců u pojištění nezvýší historické výdaje o budoucí splátky. Vrácení importu neodstraní dříve existující ani později upravené záznamy bez řešení konfliktu.

## 10. Výchozí rozhodnutí pro navazující vývoj

Tento návrh počítá s jedním vlastníkem vozidla, více vozidly na účet, reporty bez kurzových přepočtů, uchováním původních importních údajů a potvrzovaným importem. Nabíjení lze evidovat finančně a množstevně, ale skutečná spotřeba elektromobilu bez dalších údajů není slibována. Volbu technologií, přístupů a nasazení doplňuje technická koncepce; při implementaci se ověří konkrétní cílová verze MariaDB, dokončí retenční politika a podoba obrazovek. Před implementací importu se ověří význam zdrojové kategorie 7 a další varianty exportů; pro dodané přílohy je mapování a kontrolní základ definován výše.
