# CSV soubory pro import a export

Verze specifikace: 1.0, 20. 10. 2026. Stav: specifikace plánované funkce.

CCM používá dva samostatné CSV soubory: tankování/nabíjení a ostatní náklady. Struktura vychází z dodaných exportů `1724509_fuelings.csv` a `1724509_costs (1).csv` ze SpritMonitor.de. Stejná záhlaví a význam hodnot se použijí také pro export z CCM. Importní načtení a náhled se realizují v kroku 7 plánu, potvrzení zápisu v kroku 8. Tento dokument neurčuje termín realizace exportu.

## Společná pravidla

| Vlastnost | Formát |
|---|---|
| Kódování | UTF-8; import přijímá i UTF-8 s BOM, CCM exportuje bez BOM |
| Oddělovač sloupců | Středník `;` |
| Hlavička | První záznam, anglické názvy dle tabulek níže |
| Datum | `DD.MM.YYYY`, například `23.02.2010` |
| Čísla | Desetinná čárka, bez oddělovačů tisíců, například `411858,00` nebo `63,64` |
| Záporná částka | Znaménko minus před číslem, například `-5065,00` |
| Prázdná hodnota | Prázdné pole; u textu lze použít také `""`; neznamená nulu |
| Text | Dvojité uvozovky; uvnitř mohou být středníky i skutečné konce řádků |
| Escapování v profilu dodaných příloh | Uvozovka uvnitř textu jako `\"`; doslovné zpětné lomítko jako `\\` |
| Konce záznamů | CCM exportuje CRLF; import přijímá CRLF i LF včetně víceřádkových polí |
| Rozsah souboru | Jeden typ záznamů a jedno vozidlo; vozidlo se vybírá mimo obsah CSV |
| Pořadí exportu | Sestupně podle data, v rámci dne podle uloženého pořadí událostí |

Export zachová uloženou desetinnou přesnost bez ztrátového zaokrouhlení, může vynechat nevýznamné koncové nuly. Přesně dvě desetinná místa nejsou požadavkem formátu. Identifikátor vozidla v názvu příloh není interní identifikátor CCM; název importovaného souboru nerozhoduje o přiřazení vozidla.

Jednotky nejsou v původních CSV uvedeny. Při importu se potvrzuje jednotka tachometru a Trip, množství, spotřeby a rychlosti. Dodané příklady odpovídají km, l, l/100 km a km/h. Export musí uživateli uvést použité jednotky mimo CSV, aniž by přidával sloupce do tohoto formátu. Pro různá média s nejednoznačnou jednotkou se vytvoří oddělené soubory. Měna je naopak uvedena u každého záznamu, jeden soubor může obsahovat více měn.

CSV obsahuje obchodní údaje, nikoli interní ID, audit, importní dávky nebo plány. Není úplnou zálohou aplikace.

## 1. Tankování a nabíjení

Záhlaví exportu CCM je přesně v tomto pořadí:

```text
Date;Odometer;Trip;Quantity;Total price;Currency;Type;Tires;Roads;Driving style;Fuel;Note;Consumption;BC-Consumption;BC-Quantity;BC-Speed;Company;Country;Area;Location;Latitude;Longitude
```

Tabulka určuje povinnost hodnoty při importu. Export vždy obsahuje všech 22 sloupců, i když jsou některé hodnoty prázdné.

| Sloupec | Obsah | Hodnota povinná |
|---|---|---|
| Date | Datum tankování nebo nabíjení | Ano |
| Odometer | Nezáporný stav tachometru | Ne; bez něj nelze vypočítat plná–plná |
| Trip | Nezáporná deklarovaná vzdálenost od předchozího tankování | Ne |
| Quantity | Kladné množství paliva nebo energie | Ano |
| Total price | Celková cena, nikoli jednotková; nula je přípustná | Ano |
| Currency | Kód měny, například CZK, EUR, USD | Ano |
| Type | Číselný typ tankování dle číselníku | Ano |
| Tires | Číselný typ pneumatik | Ne |
| Roads | Součet kódů druhů silnic; 0 = neuvedeno | Ne |
| Driving style | Číselný styl jízdy | Ne |
| Fuel | Číselný druh paliva nebo energie | Ano |
| Note | Volný text, případně více řádků | Ne |
| Consumption | Původní deklarovaná spotřeba na 100 jednotek vzdálenosti | Ne |
| BC-Consumption | Spotřeba podle palubního počítače | Ne |
| BC-Quantity | Spotřebované množství podle palubního počítače | Ne |
| BC-Speed | Průměrná rychlost podle palubního počítače | Ne |
| Company | Provozovatel čerpacího/nabíjecího místa | Ne |
| Country | Kód země; CCM exportuje ISO alpha-2, například CZ, HR, SI | Ne |
| Area | Oblast | Ne |
| Location | Místo nebo adresa | Ne |
| Latitude | Zeměpisná šířka −90 až 90 v desetinných stupních | Ne |
| Longitude | Zeměpisná délka −180 až 180 v desetinných stupních | Ne |

Souřadnice musí být vyplněné obě, nebo obě prázdné. Nula je platná souřadnice. Trip není samostatná jízda ani automatická náhrada rozdílu tachometrů. Celková cena se nepřepočítává z poznámky; zmínka o slevě či AdBlue nevytváří další záznam.

### Číselníky tankování

| Pole | Hodnoty |
|---|---|
| Type | 0 = neplatné pro výpočet spotřeby; 1 = plné; 2 = částečné; 3 = první |
| Tires | 1 = letní; 2 = zimní; 3 = celoroční |
| Driving style | 1 = klidný; 2 = běžný; 3 = rychlý |
| Roads | 2 = dálnice; 4 = město; 8 = mimo město; hodnoty se sčítají |

Platné Roads: 0, 2, 4, 6, 8, 10, 12, 14. Například 10 znamená dálnice a mimo město. Type 0 neznamená prázdné pole ani nulovou cenu.

| Fuel | Zdrojový název | Význam |
|---|---|---|
| 1 | Diesel | Nafta |
| 2 | Biodiesel | Bionafta |
| 3 | Vegetable oil | Rostlinný olej |
| 4 | Premium Diesel | Prémiová nafta |
| 6 | Normal gasoline | Benzin Normal |
| 7 | Super gasoline | Benzin Super |
| 8 | SuperPlus gasoline | Benzin SuperPlus |
| 9 | Premium Gasoline 100 | Prémiový benzin 100 |
| 12 | LPG | LPG |
| 13 | CNG H | CNG H |
| 14 | CNG L | CNG L |
| 15 | Bio-alcohol | Bioalkohol |
| 16 | Two-stroke | Dvoutaktní směs |
| 18 | Premium Gasoline 95 | Prémiový benzin 95 |
| 19 | Electricity | Elektřina |
| 20 | E10 | Benzin E10 |
| 21 | AdBlue | AdBlue |
| 22 | Premium Gasoline 100+ | Prémiový benzin 100+ |
| 23 | Hydrogen | Vodík |
| 24 | Green electricity | Zelená elektřina |
| 25 | GTL Diesel | GTL nafta |
| 26 | HVO100 | HVO100 |

Číselné mezery se nedoplňují. AdBlue se nesčítá se spotřebou nafty; různé jednotky množství se nesčítají vůbec.

### Ilustrační záznam

Následující syntetická ukázka má stejné pořadí 22 sloupců jako hlavička:

```text
16.10.2026;125000,00;600,00;42,00;1680,00;"CZK";1;1;10;2;1;"Běžné tankování";7,00;6,80;;65,00;"Stanice";"CZ";"";"Praha";50,058743;14,313211
```

## 2. Ostatní náklady

Záhlaví podle skutečně dodaného souboru a zároveň záhlaví exportu CCM:

```text
Date;Odometer;Cost type;Total price;Currency;Note;Repetition
```

Export obsahuje vždy všech sedm sloupců v tomto pořadí. Původní příklad nemá sloupec Trip a používá textový Cost type, nikoli číselný Type.

| Sloupec | Obsah | Hodnota povinná |
|---|---|---|
| Date | Datum nákladu | Ano |
| Odometer | Nezáporný stav tachometru | Ne |
| Cost type | Přesný anglický název kategorie z tabulky níže | Ano |
| Total price | Podepsaná celková částka: kladná = výdaj, záporná = vratka/příjem | Ano |
| Currency | Kód měny | Ano |
| Note | Volný text včetně víceřádkových poznámek | Ne |
| Repetition | Interval v měsících; 0 = bez opakování | Ne; prázdná hodnota při importu znamená 0 s upozorněním |

Exportuje se vždy číselné Repetition. Povolené intervaly jsou **0, 1, 3, 6, 12, 24, 36**. Interval uchovává informaci u skutečného výdaje; sám nevytváří další platby ani aktivní plán.

### Kategorie nákladů

Číslo slouží pro alternativní číselný import. CCM do Cost type exportuje přesný anglický název.

| ID | Cost type | Význam |
|---|---|---|
| 1 | Maintenance | Údržba |
| 2 | Repair | Oprava |
| 3 | Change tires | Výměna pneumatik |
| 4 | Change oil | Výměna oleje |
| 5 | Insurance | Pojištění |
| 6 | Tax | Daň |
| 7 | Supervisory board | Pracovní překlad: technická kontrola; význam ověřit před realizací |
| 8 | Tuning | Úpravy vozidla |
| 9 | Accessories | Příslušenství |
| 10 | Purchase price | Pořizovací cena |
| 11 | Miscellaneous | Ostatní |
| 12 | Care | Péče o vozidlo |
| 13 | Payment | Platba; přesný význam ověřit před realizací |
| 14 | Registration | Registrace |
| 15 | Financing | Financování |
| 16 | Refund | Vratka |
| 17 | Fine | Pokuta |
| 18 | Parking tax | Parkovné |
| 19 | Toll | Mýtné a dálniční poplatky |
| 20 | Spare parts | Náhradní díly |
| 21 | Basic charging fee | Základní poplatek za nabíjení |
| 22 | Digital services | Digitální služby |
| 23 | GHG bonus | Bonus za snížení emisí GHG |

Znaménko částky se neodvozuje z názvu kategorie. Například záporné Insurance je vratka pojištění a zůstává v této kategorii. Kladné Refund nebo GHG bonus vyvolá při importu varování k ověření, nikoli automatické otočení znaménka.

### Ilustrační záznamy

Syntetická ukázka vratky a víceřádkové poznámky s uvozovkou uvnitř textu:

```text
11.06.2026;;Insurance;-1200,00;"CZK";"Vratka pojistného";0
12.06.2026;125100,00;Repair;3500,00;"CZK";"Výměna dílu
Kontrola systému \"A\"";0
```

## 3. Rozdíly mezi přijímaným importem a jednotným exportem

Import může přijímat níže uvedené varianty; export vždy používá základní hlavičky uvedené výše:

| Varianta importu | Jednotný export CCM |
|---|---|
| Distance místo Trip u tankování | Trip |
| Fueling type místo Type | Type |
| Fuel sort místo Fuel | Fuel |
| Odlišná velikost písmen BC-consumption, BC-quantity, BC-speed | BC-Consumption, BC-Quantity, BC-Speed |
| Type místo Cost type u nákladů; ID kategorie místo názvu | Cost type s anglickým názvem |
| Volitelný Trip u nákladů | Není součástí sedmisloupcového exportu; případná uložená hodnota se v tomto formátu nepřenáší |
| SLO jako kód Slovinska | SI |
| Chybějící nepovinné sloupce | Export obsahuje všechny sloupce, nezadané hodnoty jsou prázdné |
| Desetinná tečka či zdvojené uvozovky | Samostatně rozpoznaný importní profil; výchozí export zůstává dle společných pravidel |

Hlavičky se při importu porovnávají bez ohledu na velikost písmen a okrajové mezery. Neznámý kód, nejednoznačná hlavička či neplatná hodnota vyžadují řešení v náhledu; nedochází k tichému přepsání na „ostatní“.

Ve zdrojových přílohách profil SpritMonitor interpretuje nuly BC-Consumption, BC-Quantity a BC-Speed jako neuvedené údaje, s upozorněním a zachováním originálu v historii importu. CCM exportuje nezadané BC údaje prázdné. Při zpětném importu vlastního exportu CCM se použije profil CCM, který skutečné nuly BC zachová. Profil je nutné zvolit nebo potvrdit mimo CSV; stejné hlavičky samy zdroj spolehlivě nerozliší.

Consumption při exportu znamená uloženou původní/deklarovanou spotřebu, nikoli automaticky dosazenou spotřebu nově vypočtenou CCM. Neznámá hodnota zůstane prázdná. Díky tomu se při přenosu nemíchají údaje služby, palubního počítače a vlastního výpočtu.

## 4. Rozsah zpětného přenosu

Export a následný import do prázdného vozidla se stejnými jednotkami musí zachovat hodnoty všech sloupců tohoto formátu. Text poznámek včetně uvozovek a zalomení musí zůstat obsahově shodný. Není požadována shoda celého souboru po bajtech: může se změnit zápis koncových nul, konce záznamů, alias země či použití obalovacích uvozovek.

Interní proud média, potvrzení výpočetní kotvy, označení přerušení historie, audit, plány a další údaje bez sloupce v tomto CSV se nepřenášejí. Více proudů stejného média nelze z CSV jednoznačně obnovit; je nutné je exportovat odděleně a při importu přiřadit. Formát proto negarantuje obnovení celého stavu aplikace ani všech vypočtených ukazatelů. Exportované záznamy nejsou kvůli absenci stabilního ID automatickými aktualizacemi při opětovném importu: uplatní se náhled duplicit.

Tato specifikace popisuje kompatibilní strukturu pro CCM podle dodaných příkladů, nikoli ověřenou podporu zpětného importu do služby SpritMonitor.de.

## 5. Kontrolní základ dodaných příkladů

| Soubor | Datové záznamy | Kontrolní součet |
|---|---|---|
| 1724509_fuelings.csv | 17 | 35 016,60 CZK a 236,43 EUR |
| 1724509_costs (1).csv | 24 | 560 093,94 CZK |

Počet záznamů není počet fyzických řádků kvůli víceřádkovým poznámkám. Náklady zahrnují pořizovací cenu i zápornou vratku. Jednotlivé měny se nikdy přímo nesčítají. Původní přílohy jsou kontrolní podklady; ukázky v tomto dokumentu jsou syntetické.
