<?php

declare(strict_types=1);

trait SuggestionEngine
{
    private function GetDefaultSuggestions(): array
    {
        return [
            ['name' => 'Apfel',            'category' => 'Obst & Gemüse'],
            ['name' => 'Banane',           'category' => 'Obst & Gemüse'],
            ['name' => 'Orange',           'category' => 'Obst & Gemüse'],
            ['name' => 'Zitrone',          'category' => 'Obst & Gemüse'],
            ['name' => 'Erdbeeren',        'category' => 'Obst & Gemüse'],
            ['name' => 'Trauben',          'category' => 'Obst & Gemüse'],
            ['name' => 'Tomate',           'category' => 'Obst & Gemüse'],
            ['name' => 'Karotte',          'category' => 'Obst & Gemüse'],
            ['name' => 'Gurke',            'category' => 'Obst & Gemüse'],
            ['name' => 'Paprika',          'category' => 'Obst & Gemüse'],
            ['name' => 'Zwiebel',          'category' => 'Obst & Gemüse'],
            ['name' => 'Knoblauch',        'category' => 'Obst & Gemüse'],
            ['name' => 'Salat',            'category' => 'Obst & Gemüse'],
            ['name' => 'Kopfsalat',        'category' => 'Obst & Gemüse'],
            ['name' => 'Eisbergsalat',     'category' => 'Obst & Gemüse'],
            ['name' => 'Romana',           'category' => 'Obst & Gemüse'],
            ['name' => 'Feldsalat',        'category' => 'Obst & Gemüse'],
            ['name' => 'Rucola',           'category' => 'Obst & Gemüse'],
            ['name' => 'Chicorée',         'category' => 'Obst & Gemüse'],
            ['name' => 'Endivie',          'category' => 'Obst & Gemüse'],
            ['name' => 'Radicchio',        'category' => 'Obst & Gemüse'],
            ['name' => 'Mangold',          'category' => 'Obst & Gemüse'],
            ['name' => 'Brokkoli',         'category' => 'Obst & Gemüse'],
            ['name' => 'Milch',            'category' => 'Milch & Käse'],
            ['name' => 'Butter',           'category' => 'Milch & Käse'],
            ['name' => 'Joghurt',          'category' => 'Milch & Käse'],
            ['name' => 'Quark',            'category' => 'Milch & Käse'],
            ['name' => 'Sahne',            'category' => 'Milch & Käse'],
            ['name' => 'Käse',             'category' => 'Milch & Käse'],
            ['name' => 'Gouda',            'category' => 'Milch & Käse'],
            ['name' => 'Mozzarella',       'category' => 'Milch & Käse'],
            ['name' => 'Eier',             'category' => 'Milch & Käse'],
            ['name' => 'Edamer',           'category' => 'Milch & Käse'],
            ['name' => 'Emmentaler',       'category' => 'Milch & Käse'],
            ['name' => 'Cheddar',          'category' => 'Milch & Käse'],
            ['name' => 'Butterkäse',       'category' => 'Milch & Käse'],
            ['name' => 'Camembert',        'category' => 'Milch & Käse'],
            ['name' => 'Frischkäse',       'category' => 'Milch & Käse'],
            ['name' => 'Parmesan',         'category' => 'Milch & Käse'],
            ['name' => 'Feta',             'category' => 'Milch & Käse'],
            ['name' => 'Brie',             'category' => 'Milch & Käse'],
            ['name' => 'Bergkäse',         'category' => 'Milch & Käse'],
            ['name' => 'Ziegenkäse',       'category' => 'Milch & Käse'],
            ['name' => 'Ricotta',          'category' => 'Milch & Käse'],
            ['name' => 'Mascarpone',       'category' => 'Milch & Käse'],
            ['name' => 'Babymilch',        'category' => 'Baby & Tier'],
            ['name' => 'Toastbrot',        'category' => 'Backwaren'],
            ['name' => 'Vollkornbrot',     'category' => 'Backwaren'],
            ['name' => 'Brot',             'category' => 'Backwaren'],
            ['name' => 'Brötchen',         'category' => 'Backwaren'],
            ['name' => 'Baguette',         'category' => 'Backwaren'],
            ['name' => 'Croissant',        'category' => 'Backwaren'],
            ['name' => 'Mehl',             'category' => 'Backwaren'],
            ['name' => 'Hefe',             'category' => 'Backwaren'],
            ['name' => 'Tortilla',         'category' => 'Backwaren'],
            ['name' => 'Schrippe',         'category' => 'Backwaren'],
            ['name' => 'Semmel',           'category' => 'Backwaren'],
            ['name' => 'Weizenbrötchen',   'category' => 'Backwaren'],
            ['name' => 'Körnerbrötchen',   'category' => 'Backwaren'],
            ['name' => 'Laugenbrötchen',   'category' => 'Backwaren'],
            ['name' => 'Laugenstange',     'category' => 'Backwaren'],
            ['name' => 'Schokocroissant',  'category' => 'Backwaren'],
            ['name' => 'Milchbrötchen',    'category' => 'Backwaren'],
            ['name' => 'Rosinenbrötchen',  'category' => 'Backwaren'],
            ['name' => 'Franzbrötchen',    'category' => 'Backwaren'],
            ['name' => 'Plunder',          'category' => 'Backwaren'],
            ['name' => 'Streuseltaler',    'category' => 'Backwaren'],
            ['name' => 'Hörnchen',         'category' => 'Backwaren'],
            ['name' => 'Baguettebrötchen', 'category' => 'Backwaren'],
            ['name' => 'Kaiserbrötchen',   'category' => 'Backwaren'],
            ['name' => 'Mohnbrötchen',     'category' => 'Backwaren'],
            ['name' => 'Sesambrötchen',    'category' => 'Backwaren'],
            ['name' => 'Käsebrötchen',     'category' => 'Backwaren'],
            ['name' => 'Weißbrot',         'category' => 'Backwaren'],
            ['name' => 'Mischbrot',        'category' => 'Backwaren'],
            ['name' => 'Roggenbrot',       'category' => 'Backwaren'],
            ['name' => 'Dinkelbrot',       'category' => 'Backwaren'],
            ['name' => 'Sauerteigbrot',    'category' => 'Backwaren'],
            ['name' => 'Bauernbrot',       'category' => 'Backwaren'],
            ['name' => 'Schwarzbrot',      'category' => 'Backwaren'],
            ['name' => 'Pumpernickel',     'category' => 'Backwaren'],
            ['name' => 'Körnerbrot',       'category' => 'Backwaren'],
            ['name' => 'Kartoffelbrot',    'category' => 'Backwaren'],
            ['name' => 'Ciabatta',         'category' => 'Backwaren'],
            ['name' => 'Brioche',          'category' => 'Backwaren'],
            ['name' => 'Fladenbrot',       'category' => 'Backwaren'],
            ['name' => 'Knäckebrot',       'category' => 'Backwaren'],
            ['name' => 'Hähnchenfilet',    'category' => 'Fleisch & Wurst'],
            ['name' => 'Hackfleisch',      'category' => 'Fleisch & Wurst'],
            ['name' => 'Schweinefilet',    'category' => 'Fleisch & Wurst'],
            ['name' => 'Rindfleisch',      'category' => 'Fleisch & Wurst'],
            ['name' => 'Lachs',            'category' => 'Fleisch & Wurst'],
            ['name' => 'Thunfisch',        'category' => 'Fleisch & Wurst'],
            ['name' => 'Salami',           'category' => 'Fleisch & Wurst'],
            ['name' => 'Schinken',         'category' => 'Fleisch & Wurst'],
            ['name' => 'Würstchen',        'category' => 'Fleisch & Wurst'],
            ['name' => 'Aufschnitt',       'category' => 'Fleisch & Wurst'],
            ['name' => 'Kochschinken',     'category' => 'Fleisch & Wurst'],
            ['name' => 'Rohschinken',      'category' => 'Fleisch & Wurst'],
            ['name' => 'Putenschinken',    'category' => 'Fleisch & Wurst'],
            ['name' => 'Hähnchenbrustaufschnitt', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Mortadella',       'category' => 'Fleisch & Wurst'],
            ['name' => 'Fleischwurst',     'category' => 'Fleisch & Wurst'],
            ['name' => 'Leberwurst',       'category' => 'Fleisch & Wurst'],
            ['name' => 'Teewurst',         'category' => 'Fleisch & Wurst'],
            ['name' => 'Geflügelwurst',    'category' => 'Fleisch & Wurst'],
            ['name' => 'Schinkenwurst',    'category' => 'Fleisch & Wurst'],
            ['name' => 'Bierwurst',        'category' => 'Fleisch & Wurst'],
            ['name' => 'Putenbrust',       'category' => 'Fleisch & Wurst'],
            ['name' => 'Tiefkühlpizza',    'category' => 'Tiefkühl'],
            ['name' => 'Tiefkühlgemüse',   'category' => 'Tiefkühl'],
            ['name' => 'Tiefkühlfisch',    'category' => 'Tiefkühl'],
            ['name' => 'Fischstäbchen',    'category' => 'Tiefkühl'],
            ['name' => 'Eis',              'category' => 'Tiefkühl'],
            ['name' => 'Pommes',           'category' => 'Tiefkühl'],
            ['name' => 'Spinat',           'category' => 'Tiefkühl'],
            ['name' => 'Erbsen',           'category' => 'Tiefkühl'],
            ['name' => 'Mineralwasser',    'category' => 'Getränke'],
            ['name' => 'Orangensaft',      'category' => 'Getränke'],
            ['name' => 'Apfelsaft',        'category' => 'Getränke'],
            ['name' => 'Milchgetränk',     'category' => 'Getränke'],
            ['name' => 'Limonade',         'category' => 'Getränke'],
            ['name' => 'Cola',             'category' => 'Getränke'],
            ['name' => 'Bier',             'category' => 'Getränke'],
            ['name' => 'Wein',             'category' => 'Getränke'],
            ['name' => 'Kaffee',           'category' => 'Getränke'],
            ['name' => 'Tee',              'category' => 'Getränke'],
            ['name' => 'Milchschokolade',  'category' => 'Snacks & Süßes'],
            ['name' => 'Schokolade',       'category' => 'Snacks & Süßes'],
            ['name' => 'Gummibärchen',     'category' => 'Snacks & Süßes'],
            ['name' => 'Chips',            'category' => 'Snacks & Süßes'],
            ['name' => 'Kekse',            'category' => 'Snacks & Süßes'],
            ['name' => 'Nüsse',            'category' => 'Snacks & Süßes'],
            ['name' => 'Popcorn',          'category' => 'Snacks & Süßes'],
            ['name' => 'Bonbons',          'category' => 'Snacks & Süßes'],
            ['name' => 'Waffeln',          'category' => 'Snacks & Süßes'],
            ['name' => 'Riegel',           'category' => 'Snacks & Süßes'],
            ['name' => 'Cracker',          'category' => 'Snacks & Süßes'],
            ['name' => 'Pralinen',         'category' => 'Snacks & Süßes'],
            ['name' => 'Schokoriegel',     'category' => 'Snacks & Süßes'],
            ['name' => 'Keksriegel',       'category' => 'Snacks & Süßes'],
            ['name' => 'Doppelkekse',      'category' => 'Snacks & Süßes'],
            ['name' => 'Butterkekse',      'category' => 'Snacks & Süßes'],
            ['name' => 'Gebäckmischung',   'category' => 'Snacks & Süßes'],
            ['name' => 'Fruchtgummi',      'category' => 'Snacks & Süßes'],
            ['name' => 'Weingummi',        'category' => 'Snacks & Süßes'],
            ['name' => 'Lakritz',          'category' => 'Snacks & Süßes'],
            ['name' => 'Kaubonbons',       'category' => 'Snacks & Süßes'],
            ['name' => 'Toffees',          'category' => 'Snacks & Süßes'],
            ['name' => 'Karamellbonbons',  'category' => 'Snacks & Süßes'],
            ['name' => 'Lutscher',         'category' => 'Snacks & Süßes'],
            ['name' => 'Marshmallows',     'category' => 'Snacks & Süßes'],
            ['name' => 'Kaugummi',         'category' => 'Snacks & Süßes'],
            ['name' => 'Dragees',          'category' => 'Snacks & Süßes'],
            ['name' => 'Schokoerdnüsse',   'category' => 'Snacks & Süßes'],
            ['name' => 'Schokolinsen',     'category' => 'Snacks & Süßes'],
            ['name' => 'Nougat',           'category' => 'Snacks & Süßes'],
            ['name' => 'Marzipan',         'category' => 'Snacks & Süßes'],
            ['name' => 'Geleefrüchte',     'category' => 'Snacks & Süßes'],
            ['name' => 'Puffreisriegel',   'category' => 'Snacks & Süßes'],
            ['name' => 'Tomatenmark',      'category' => 'Konserven & Trocken'],
            ['name' => 'Dosentomaten',     'category' => 'Konserven & Trocken'],
            ['name' => 'Kichererbsen',     'category' => 'Konserven & Trocken'],
            ['name' => 'Mais',             'category' => 'Konserven & Trocken'],
            ['name' => 'Nudeln',           'category' => 'Konserven & Trocken'],
            ['name' => 'Spaghetti',        'category' => 'Konserven & Trocken'],
            ['name' => 'Reis',             'category' => 'Konserven & Trocken'],
            ['name' => 'Linsen',           'category' => 'Konserven & Trocken'],
            ['name' => 'Bohnen',           'category' => 'Konserven & Trocken'],
            ['name' => 'Müsli',            'category' => 'Konserven & Trocken'],
            ['name' => 'Haferflocken',     'category' => 'Konserven & Trocken'],
            ['name' => 'Cornflakes',       'category' => 'Konserven & Trocken'],
            ['name' => 'Zucker',           'category' => 'Konserven & Trocken'],
            ['name' => 'Salz',             'category' => 'Konserven & Trocken'],
            ['name' => 'Pfeffer',          'category' => 'Konserven & Trocken'],
            ['name' => 'Paprikapulver edelsüß',    'category' => 'Konserven & Trocken'],
            ['name' => 'Paprikapulver rosenscharf', 'category' => 'Konserven & Trocken'],
            ['name' => 'Curry',            'category' => 'Konserven & Trocken'],
            ['name' => 'Kreuzkümmel',      'category' => 'Konserven & Trocken'],
            ['name' => 'Oregano',          'category' => 'Konserven & Trocken'],
            ['name' => 'Basilikum',        'category' => 'Konserven & Trocken'],
            ['name' => 'Thymian',          'category' => 'Konserven & Trocken'],
            ['name' => 'Rosmarin',         'category' => 'Konserven & Trocken'],
            ['name' => 'Majoran',          'category' => 'Konserven & Trocken'],
            ['name' => 'Zimt',             'category' => 'Konserven & Trocken'],
            ['name' => 'Muskat',           'category' => 'Konserven & Trocken'],
            ['name' => 'Knoblauchpulver',  'category' => 'Konserven & Trocken'],
            ['name' => 'Zwiebelpulver',    'category' => 'Konserven & Trocken'],
            ['name' => 'Chiliflocken',     'category' => 'Konserven & Trocken'],
            ['name' => 'Cayennepfeffer',   'category' => 'Konserven & Trocken'],
            ['name' => 'Kurkuma',          'category' => 'Konserven & Trocken'],
            ['name' => 'Ingwer',           'category' => 'Konserven & Trocken'],
            ['name' => 'Lorbeerblätter',   'category' => 'Konserven & Trocken'],
            ['name' => 'Marmelade',        'category' => 'Konserven & Trocken'],
            ['name' => 'Konfitüre',        'category' => 'Konserven & Trocken'],
            ['name' => 'Fruchtaufstrich',  'category' => 'Konserven & Trocken'],
            ['name' => 'Honig',            'category' => 'Konserven & Trocken'],
            ['name' => 'Nuss-Nougat-Creme', 'category' => 'Konserven & Trocken'],
            ['name' => 'Erdnussbutter',    'category' => 'Konserven & Trocken'],
            ['name' => 'Mandelcreme',      'category' => 'Konserven & Trocken'],
            ['name' => 'Schokocreme',      'category' => 'Konserven & Trocken'],
            ['name' => 'Karamellcreme',    'category' => 'Konserven & Trocken'],
            ['name' => 'Zuckerrübensirup', 'category' => 'Konserven & Trocken'],
            ['name' => 'Ahornsirup',       'category' => 'Konserven & Trocken'],
            ['name' => 'Pflaumenmus',      'category' => 'Konserven & Trocken'],
            ['name' => 'Lemon Curd',       'category' => 'Konserven & Trocken'],
            ['name' => 'Maronencreme',     'category' => 'Konserven & Trocken'],
            ['name' => 'Sonnencreme',      'category' => 'Hygiene & Pflege'],
            ['name' => 'Wattestäbchen',    'category' => 'Hygiene & Pflege'],
            ['name' => 'Taschentücher',    'category' => 'Hygiene & Pflege'],
            ['name' => 'Zahnbürste',       'category' => 'Hygiene & Pflege'],
            ['name' => 'Handcreme',        'category' => 'Hygiene & Pflege'],
            ['name' => 'Zahnpasta',        'category' => 'Hygiene & Pflege'],
            ['name' => 'Deodorant',        'category' => 'Hygiene & Pflege'],
            ['name' => 'Rasierer',         'category' => 'Hygiene & Pflege'],
            ['name' => 'Duschgel',         'category' => 'Hygiene & Pflege'],
            ['name' => 'Shampoo',          'category' => 'Hygiene & Pflege'],
            ['name' => 'Tampons',          'category' => 'Hygiene & Pflege'],
            ['name' => 'Binden',           'category' => 'Hygiene & Pflege'],
            ['name' => 'Geschirrspültabs', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Toilettenpapier',  'category' => 'Haushalt & Reinigung'],
            ['name' => 'Küchenrolle',      'category' => 'Haushalt & Reinigung'],
            ['name' => 'Spülmittel',       'category' => 'Haushalt & Reinigung'],
            ['name' => 'Waschmittel',      'category' => 'Haushalt & Reinigung'],
            ['name' => 'Müllbeutel',       'category' => 'Haushalt & Reinigung'],
            ['name' => 'Backpapier',       'category' => 'Haushalt & Reinigung'],
            ['name' => 'Alufolie',         'category' => 'Haushalt & Reinigung'],
            ['name' => 'Schwamm',          'category' => 'Haushalt & Reinigung'],
            ['name' => 'Glasreiniger',     'category' => 'Haushalt & Reinigung'],
            ['name' => 'Allzweckreiniger', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Badreiniger',      'category' => 'Haushalt & Reinigung'],
            ['name' => 'WC-Reiniger',      'category' => 'Haushalt & Reinigung'],
            ['name' => 'Bodenreiniger',    'category' => 'Haushalt & Reinigung'],
            ['name' => 'Entkalker',        'category' => 'Haushalt & Reinigung'],
            ['name' => 'Fettlöser',        'category' => 'Haushalt & Reinigung'],
            ['name' => 'Flüssigwaschmittel', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Weichspüler',      'category' => 'Haushalt & Reinigung'],
            ['name' => 'Fleckenentferner', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Pulverwaschmittel', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Babynahrung',      'category' => 'Baby & Tier'],
            ['name' => 'Windeln',          'category' => 'Baby & Tier'],
            ['name' => 'Hundefutter',      'category' => 'Baby & Tier'],
            ['name' => 'Katzenfutter',     'category' => 'Baby & Tier'],
            ['name' => 'Katzenstreu',      'category' => 'Baby & Tier'],
            ['name' => 'Hundesnacks',      'category' => 'Baby & Tier'],
            ['name' => 'Tiernahrung',      'category' => 'Baby & Tier'],
        ];
    }

    private function LoadSuggestionItems(): array
    {
        $raw  = $this->ReadAttributeString('SuggestionItems');
        $data = json_decode($raw, true);
        if (is_array($data) && count($data) > 0) {
            return $data;
        }
        // Lazy-init: write defaults + migrate any existing ItemHistory
        $items = $this->GetDefaultSuggestions();
        $seen  = [];
        foreach ($items as $item) {
            $seen[mb_strtolower(trim($item['name']))] = true;
        }
        $hRaw    = $this->ReadAttributeString('ItemHistory');
        $history = json_decode($hRaw, true);
        if (is_array($history)) {
            foreach ($history as $entry) {
                $key = mb_strtolower(trim($entry['name'] ?? ''));
                if ($key !== '' && !isset($seen[$key])) {
                    $items[] = ['name' => $entry['name'], 'category' => $entry['category'] ?? ''];
                    $seen[$key] = true;
                }
            }
        }
        $this->SaveSuggestionItems($items);
        return $items;
    }

    private function SaveSuggestionItems(array $items): void
    {
        $this->WriteAttributeString(
            'SuggestionItems',
            json_encode(array_values($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function GetBaseSuggestions(): array
    {
        $items  = $this->LoadSuggestionItems();
        $result = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['name']) && trim($item['name']) !== '') {
                $result[trim($item['name'])] = trim($item['category'] ?? '');
            }
        }
        return $result;
    }

    private function LookupCategory(string $Name): string
    {
        // 1) Explicit user overrides (highest priority)
        $raw       = $this->ReadAttributeString('CategoryOverrides');
        $overrides = json_decode($raw, true);
        if (is_array($overrides)) {
            $key = mb_strtolower(trim($Name));
            if (isset($overrides[$key]) && $overrides[$key] !== '') {
                return $overrides[$key];
            }
        }

        // 2) Brand map (word-boundary match so 'Mars' does not hit 'Marshmallows')
        $nameLc = mb_strtolower($Name);
        foreach ($this->GetBrandCategoryMap() as $brand => $cat) {
            $pattern = '/(?<![\\p{L}\\p{N}])' . preg_quote(mb_strtolower($brand), '/') . '(?![\\p{L}\\p{N}])/u';
            if (preg_match($pattern, $nameLc) === 1) {
                return $cat;
            }
        }

        // 3) Base suggestions (skip empty categories from legacy history entries)
        $suggestions = $this->GetBaseSuggestions();
        $keys = array_keys($suggestions);
        usort($keys, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($keys as $key) {
            if (trim((string)$suggestions[$key]) === '') {
                continue;
            }
            if (mb_stripos($Name, $key) !== false) {
                return $suggestions[$key];
            }
        }

        return $this->Translate('Miscellaneous');
    }

    private function GetBrandCategoryMap(): array
    {
        return [
            // Snacks & Süßes
            'Haribo'      => 'Snacks & Süßes',
            'Katjes'      => 'Snacks & Süßes',
            'Milka'       => 'Snacks & Süßes',
            'Ritter Sport'=> 'Snacks & Süßes',
            'Lindt'       => 'Snacks & Süßes',
            'Ferrero'     => 'Snacks & Süßes',
            'Nutella'     => 'Snacks & Süßes',
            'Kinder'      => 'Snacks & Süßes',
            'Storck'      => 'Snacks & Süßes',
            'Toffifee'    => 'Snacks & Süßes',
            'Merci'       => 'Snacks & Süßes',
            'Duplo'       => 'Snacks & Süßes',
            'Raffaello'   => 'Snacks & Süßes',
            'Snickers'    => 'Snacks & Süßes',
            'Mars'        => 'Snacks & Süßes',
            'Twix'        => 'Snacks & Süßes',
            'Bounty'      => 'Snacks & Süßes',
            'M&M'         => 'Snacks & Süßes',
            'Nimm2'       => 'Snacks & Süßes',
            "Werther's"   => 'Snacks & Süßes',
            'Hanuta'      => 'Snacks & Süßes',
            'Oreo'        => 'Snacks & Süßes',
            'Leibniz'     => 'Snacks & Süßes',
            'Bahlsen'     => 'Snacks & Süßes',
            'Pringles'    => 'Snacks & Süßes',
            'Lorenz'      => 'Snacks & Süßes',
            'Funny-frisch'=> 'Snacks & Süßes',
            'Chio'        => 'Snacks & Süßes',
            // Getränke
            'Coca-Cola'   => 'Getränke',
            'Coca Cola'   => 'Getränke',
            'Pepsi'       => 'Getränke',
            'Fanta'       => 'Getränke',
            'Sprite'      => 'Getränke',
            'Red Bull'    => 'Getränke',
            'Rauch'       => 'Getränke',
            'Granini'     => 'Getränke',
            'Hohes C'     => 'Getränke',
            // Milch & Käse
            'Müller'      => 'Milch & Käse',
            'Landliebe'   => 'Milch & Käse',
            'Almighurt'   => 'Milch & Käse',
            'Danone'      => 'Milch & Käse',
            'Activia'     => 'Milch & Käse',
            // Fleisch & Wurst
            'Rügenwalder' => 'Fleisch & Wurst',
        ];
    }

    private function SaveCategoryOverride(string $Name, string $Category): void
    {
        $key = mb_strtolower(trim($Name));
        if ($key === '' || trim($Category) === '') {
            return;
        }
        $raw       = $this->ReadAttributeString('CategoryOverrides');
        $overrides = json_decode($raw, true);
        if (!is_array($overrides)) {
            $overrides = [];
        }
        $overrides[$key] = trim($Category);
        $this->WriteAttributeString(
            'CategoryOverrides',
            json_encode($overrides, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    private function TrackFrequency(string $Name, string $Category = ''): void
    {
        $key = mb_strtolower(trim($Name));
        if ($key === '') {
            return;
        }
        $semaphoreKey = 'SL_Freq_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphoreKey, 500)) {
            $this->SendDebug('SuggestionEngine', 'Semaphore timeout on TrackFrequency', 0);
            return;
        }
        try {
            $raw   = $this->ReadAttributeString('Frequencies');
            $freqs = json_decode($raw, true);
            if (!is_array($freqs)) {
                $freqs = [];
            }
            $freqs[$key] = ((int)($freqs[$key] ?? 0)) + 1;
            $this->WriteAttributeString('Frequencies', json_encode($freqs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            // Add custom items directly to SuggestionItems
            $items = $this->LoadSuggestionItems();
            $found = false;
            foreach ($items as $item) {
                if (mb_strtolower(trim($item['name'])) === $key) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $items[] = ['name' => trim($Name), 'category' => trim($Category)];
                $this->SaveSuggestionItems($items);
            }
        } finally {
            IPS_SemaphoreLeave($semaphoreKey);
        }
    }

    private function SyncSuggestionsFromConfig(): void
    {
        $raw = $this->ReadPropertyString('SuggestionItemsConfig');
        $config = json_decode($raw, true);
        if (!is_array($config) || count($config) === 0) {
            return;
        }
        $clean = [];
        foreach ($config as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $clean[] = [
                'name'     => $name,
                'category' => trim((string)($row['category'] ?? '')),
            ];
        }
        $this->SaveSuggestionItems($clean);
    }

    public function GetSuggestions(): string
    {
        $base  = $this->GetBaseSuggestions();
        $raw   = $this->ReadAttributeString('Frequencies');
        $freqs = json_decode($raw, true);
        if (!is_array($freqs)) {
            $freqs = [];
        }

        $ranked   = [];
        $unranked = [];

        foreach ($base as $name => $category) {
            $freqKey = mb_strtolower(trim($name));
            $freq    = (int)($freqs[$freqKey] ?? 0);
            $entry   = ['name' => $name, 'category' => $category, 'frequency' => $freq];
            if ($freq > 0) {
                $ranked[] = $entry;
            } else {
                $unranked[] = $entry;
            }
        }

        usort($ranked, fn($a, $b) => $b['frequency'] <=> $a['frequency']);

        return json_encode(array_merge($ranked, $unranked), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
