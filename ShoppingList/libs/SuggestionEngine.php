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
            ['name' => 'Salatgurke',       'category' => 'Obst & Gemüse'],
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
            ['name' => 'Eistee',           'category' => 'Getränke'],
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
            ['name' => 'Klopapier',        'category' => 'Haushalt & Reinigung'],
            ['name' => 'WC-Papier',        'category' => 'Haushalt & Reinigung'],
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

            // Erweiterung 2026-07: gängige Artikel + regionale Begriffe/Schreibweisen (DACH)
            ['name' => 'Kartoffeln', 'category' => 'Obst & Gemüse'],
            ['name' => 'Birne', 'category' => 'Obst & Gemüse'],
            ['name' => 'Kiwi', 'category' => 'Obst & Gemüse'],
            ['name' => 'Melone', 'category' => 'Obst & Gemüse'],
            ['name' => 'Wassermelone', 'category' => 'Obst & Gemüse'],
            ['name' => 'Zucchini', 'category' => 'Obst & Gemüse'],
            ['name' => 'Aubergine', 'category' => 'Obst & Gemüse'],
            ['name' => 'Champignons', 'category' => 'Obst & Gemüse'],
            ['name' => 'Pilze', 'category' => 'Obst & Gemüse'],
            ['name' => 'Blumenkohl', 'category' => 'Obst & Gemüse'],
            ['name' => 'Kohlrabi', 'category' => 'Obst & Gemüse'],
            ['name' => 'Spargel', 'category' => 'Obst & Gemüse'],
            ['name' => 'Kürbis', 'category' => 'Obst & Gemüse'],
            ['name' => 'Süßkartoffel', 'category' => 'Obst & Gemüse'],
            ['name' => 'Himbeeren', 'category' => 'Obst & Gemüse'],
            ['name' => 'Heidelbeeren', 'category' => 'Obst & Gemüse'],
            ['name' => 'Ananas', 'category' => 'Obst & Gemüse'],
            ['name' => 'Mango', 'category' => 'Obst & Gemüse'],
            ['name' => 'Pfirsich', 'category' => 'Obst & Gemüse'],
            ['name' => 'Nektarine', 'category' => 'Obst & Gemüse'],
            ['name' => 'Pflaumen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Kirschen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Avocado', 'category' => 'Obst & Gemüse'],
            ['name' => 'Limette', 'category' => 'Obst & Gemüse'],
            ['name' => 'Mandarinen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Clementinen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Grapefruit', 'category' => 'Obst & Gemüse'],
            ['name' => 'Radieschen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Lauch', 'category' => 'Obst & Gemüse'],
            ['name' => 'Sellerie', 'category' => 'Obst & Gemüse'],
            ['name' => 'Fenchel', 'category' => 'Obst & Gemüse'],
            ['name' => 'Rote Bete', 'category' => 'Obst & Gemüse'],
            ['name' => 'Weißkohl', 'category' => 'Obst & Gemüse'],
            ['name' => 'Rotkohl', 'category' => 'Obst & Gemüse'],
            ['name' => 'Rosenkohl', 'category' => 'Obst & Gemüse'],
            ['name' => 'Frühlingszwiebeln', 'category' => 'Obst & Gemüse'],
            ['name' => 'Petersilie', 'category' => 'Obst & Gemüse'],
            ['name' => 'Schnittlauch', 'category' => 'Obst & Gemüse'],
            ['name' => 'Möhre', 'category' => 'Obst & Gemüse'],
            ['name' => 'Mohrrübe', 'category' => 'Obst & Gemüse'],
            ['name' => 'Gelbe Rübe', 'category' => 'Obst & Gemüse'],
            ['name' => 'Rüebli', 'category' => 'Obst & Gemüse'],
            ['name' => 'Erdäpfel', 'category' => 'Obst & Gemüse'],
            ['name' => 'Apfelsine', 'category' => 'Obst & Gemüse'],
            ['name' => 'Paradeiser', 'category' => 'Obst & Gemüse'],
            ['name' => 'Aprikose', 'category' => 'Obst & Gemüse'],
            ['name' => 'Marille', 'category' => 'Obst & Gemüse'],
            ['name' => 'Zwetschgen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Zwetschen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Porree', 'category' => 'Obst & Gemüse'],
            ['name' => 'Rotkraut', 'category' => 'Obst & Gemüse'],
            ['name' => 'Blaukraut', 'category' => 'Obst & Gemüse'],
            ['name' => 'Weißkraut', 'category' => 'Obst & Gemüse'],
            ['name' => 'Schwammerl', 'category' => 'Obst & Gemüse'],
            ['name' => 'Karfiol', 'category' => 'Obst & Gemüse'],
            ['name' => 'Melanzani', 'category' => 'Obst & Gemüse'],
            ['name' => 'Fisolen', 'category' => 'Obst & Gemüse'],
            ['name' => 'Vogerlsalat', 'category' => 'Obst & Gemüse'],
            ['name' => 'Peperoni', 'category' => 'Obst & Gemüse'],
            ['name' => 'Rote Rübe', 'category' => 'Obst & Gemüse'],
            ['name' => 'Blaubeeren', 'category' => 'Obst & Gemüse'],
            ['name' => 'Skyr', 'category' => 'Milch & Käse'],
            ['name' => 'Kefir', 'category' => 'Milch & Käse'],
            ['name' => 'Buttermilch', 'category' => 'Milch & Käse'],
            ['name' => 'Schmand', 'category' => 'Milch & Käse'],
            ['name' => 'Crème fraîche', 'category' => 'Milch & Käse'],
            ['name' => 'Halloumi', 'category' => 'Milch & Käse'],
            ['name' => 'Hüttenkäse', 'category' => 'Milch & Käse'],
            ['name' => 'Pudding', 'category' => 'Milch & Käse'],
            ['name' => 'Milchreis', 'category' => 'Milch & Käse'],
            ['name' => 'Margarine', 'category' => 'Milch & Käse'],
            ['name' => 'Hafermilch', 'category' => 'Milch & Käse'],
            ['name' => 'Mandelmilch', 'category' => 'Milch & Käse'],
            ['name' => 'Kondensmilch', 'category' => 'Milch & Käse'],
            ['name' => 'Topfen', 'category' => 'Milch & Käse'],
            ['name' => 'Schlagobers', 'category' => 'Milch & Käse'],
            ['name' => 'Obers', 'category' => 'Milch & Käse'],
            ['name' => 'Rahm', 'category' => 'Milch & Käse'],
            ['name' => 'Jogurt', 'category' => 'Milch & Käse'],
            ['name' => 'Toast', 'category' => 'Backwaren'],
            ['name' => 'Kuchen', 'category' => 'Backwaren'],
            ['name' => 'Muffins', 'category' => 'Backwaren'],
            ['name' => 'Brezel', 'category' => 'Backwaren'],
            ['name' => 'Zwieback', 'category' => 'Backwaren'],
            ['name' => 'Stuten', 'category' => 'Backwaren'],
            ['name' => 'Wraps', 'category' => 'Backwaren'],
            ['name' => 'Berliner', 'category' => 'Backwaren'],
            ['name' => 'Hefezopf', 'category' => 'Backwaren'],
            ['name' => 'Pizzateig', 'category' => 'Backwaren'],
            ['name' => 'Wecken', 'category' => 'Backwaren'],
            ['name' => 'Weckle', 'category' => 'Backwaren'],
            ['name' => 'Weckerl', 'category' => 'Backwaren'],
            ['name' => 'Weggli', 'category' => 'Backwaren'],
            ['name' => 'Breze', 'category' => 'Backwaren'],
            ['name' => 'Brezn', 'category' => 'Backwaren'],
            ['name' => 'Krapfen', 'category' => 'Backwaren'],
            ['name' => 'Kreppel', 'category' => 'Backwaren'],
            ['name' => 'Pfannkuchen', 'category' => 'Backwaren'],
            ['name' => 'Kipferl', 'category' => 'Backwaren'],
            ['name' => 'Zopf', 'category' => 'Backwaren'],
            ['name' => 'Germ', 'category' => 'Backwaren'],
            ['name' => 'Bratwurst', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Steak', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Schnitzel', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Speck', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Bacon', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Gulasch', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Hähnchenbrust', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Hähnchenschenkel', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Mett', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Leberkäse', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Garnelen', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Forelle', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Fischfilet', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Tofu', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Gehacktes', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Faschiertes', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Frikadellen', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Buletten', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Fleischpflanzerl', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Fleischküchle', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Fleischkäse', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Kasseler', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Tunfisch', 'category' => 'Fleisch & Wurst'],
            ['name' => 'Tiefkühlbeeren', 'category' => 'Tiefkühl'],
            ['name' => 'Lasagne', 'category' => 'Tiefkühl'],
            ['name' => 'Blätterteig', 'category' => 'Tiefkühl'],
            ['name' => 'Apfelschorle', 'category' => 'Getränke'],
            ['name' => 'Spezi', 'category' => 'Getränke'],
            ['name' => 'Energydrink', 'category' => 'Getränke'],
            ['name' => 'Sekt', 'category' => 'Getränke'],
            ['name' => 'Prosecco', 'category' => 'Getränke'],
            ['name' => 'Radler', 'category' => 'Getränke'],
            ['name' => 'Kakao', 'category' => 'Getränke'],
            ['name' => 'Smoothie', 'category' => 'Getränke'],
            ['name' => 'Sirup', 'category' => 'Getränke'],
            ['name' => 'Sprudel', 'category' => 'Getränke'],
            ['name' => 'Selters', 'category' => 'Getränke'],
            ['name' => 'Studentenfutter', 'category' => 'Snacks & Süßes'],
            ['name' => 'Salzstangen', 'category' => 'Snacks & Süßes'],
            ['name' => 'Reiswaffeln', 'category' => 'Snacks & Süßes'],
            ['name' => 'Plätzchen', 'category' => 'Snacks & Süßes'],
            ['name' => 'Öl', 'category' => 'Konserven & Trocken'],
            ['name' => 'Olivenöl', 'category' => 'Konserven & Trocken'],
            ['name' => 'Essig', 'category' => 'Konserven & Trocken'],
            ['name' => 'Senf', 'category' => 'Konserven & Trocken'],
            ['name' => 'Ketchup', 'category' => 'Konserven & Trocken'],
            ['name' => 'Mayonnaise', 'category' => 'Konserven & Trocken'],
            ['name' => 'Sojasauce', 'category' => 'Konserven & Trocken'],
            ['name' => 'Pesto', 'category' => 'Konserven & Trocken'],
            ['name' => 'Passierte Tomaten', 'category' => 'Konserven & Trocken'],
            ['name' => 'Kokosmilch', 'category' => 'Konserven & Trocken'],
            ['name' => 'Gemüsebrühe', 'category' => 'Konserven & Trocken'],
            ['name' => 'Penne', 'category' => 'Konserven & Trocken'],
            ['name' => 'Gnocchi', 'category' => 'Konserven & Trocken'],
            ['name' => 'Couscous', 'category' => 'Konserven & Trocken'],
            ['name' => 'Backpulver', 'category' => 'Konserven & Trocken'],
            ['name' => 'Vanillezucker', 'category' => 'Konserven & Trocken'],
            ['name' => 'Puderzucker', 'category' => 'Konserven & Trocken'],
            ['name' => 'Speisestärke', 'category' => 'Konserven & Trocken'],
            ['name' => 'Paniermehl', 'category' => 'Konserven & Trocken'],
            ['name' => 'Grieß', 'category' => 'Konserven & Trocken'],
            ['name' => 'Gewürzgurken', 'category' => 'Konserven & Trocken'],
            ['name' => 'Apfelmus', 'category' => 'Konserven & Trocken'],
            ['name' => 'Meerrettich', 'category' => 'Konserven & Trocken'],
            ['name' => 'Kren', 'category' => 'Konserven & Trocken'],
            ['name' => 'Staubzucker', 'category' => 'Konserven & Trocken'],
            ['name' => 'Semmelbrösel', 'category' => 'Konserven & Trocken'],
            ['name' => 'Knödel', 'category' => 'Konserven & Trocken'],
            ['name' => 'Klöße', 'category' => 'Konserven & Trocken'],
            ['name' => 'Sauerkraut', 'category' => 'Konserven & Trocken'],
            ['name' => 'Majonäse', 'category' => 'Konserven & Trocken'],
            ['name' => 'Müesli', 'category' => 'Konserven & Trocken'],
            ['name' => 'Seife', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Zahnseide', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Mundspülung', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Rasierschaum', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Rasierklingen', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Bodylotion', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Lippenpflege', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Feuchttücher', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Wattepads', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Pflaster', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Sonnenmilch', 'category' => 'Hygiene & Pflege'],
            ['name' => 'Frischhaltefolie', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Gefrierbeutel', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Batterien', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Kerzen', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Teelichter', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Streichhölzer', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Feuerzeug', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Servietten', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Spülbürste', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Putzlappen', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Mikrofasertücher', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Gummihandschuhe', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Klarspüler', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Spülmaschinensalz', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Grillkohle', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Küchentücher', 'category' => 'Haushalt & Reinigung'],
            ['name' => 'Babybrei', 'category' => 'Baby & Tier'],
            ['name' => 'Schnuller', 'category' => 'Baby & Tier'],
            ['name' => 'Vogelfutter', 'category' => 'Baby & Tier'],
            ['name' => 'Leckerlis', 'category' => 'Baby & Tier'],
        ];
    }

    private function LoadSuggestionItems(): array
    {
        $raw  = $this->ReadAttributeString('SuggestionItems');
        $data = json_decode($raw, true);
        if (is_array($data) && count($data) > 0) {
            // Defaults nachmergen: sonst erreichen Vokabular-Erweiterungen
            // Bestandsinstanzen nie (das Attribut friert den Erststand ein)
            return $this->MergeDefaultSuggestions($data);
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

    /** Ergänzt neue Default-Einträge, ohne gelernte/angepasste zu überschreiben. */
    private function MergeDefaultSuggestions(array $items): array
    {
        $seen = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['name'])) {
                $seen[mb_strtolower(trim((string)$item['name']))] = true;
            }
        }
        $added = false;
        foreach ($this->GetDefaultSuggestions() as $default) {
            if (!isset($seen[mb_strtolower(trim($default['name']))])) {
                $items[] = $default;
                $added   = true;
            }
        }
        if ($added) {
            // Einmalig persistieren, damit der Merge nicht bei jedem Lookup läuft
            $this->SaveSuggestionItems($items);
        }
        return $items;
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
