import fs from "node:fs/promises";
import path from "node:path";

const apiKey = process.env.OPENAI_API_KEY;

if (!apiKey) {
  console.error("OPENAI_API_KEY is not set.");
  process.exit(1);
}

const config = {
  model:
    parseSingleValueArg(process.argv, "--model=") ||
    process.env.OPENAI_IMAGE_MODEL ||
    "gpt-image-1",
  size: process.env.OPENAI_IMAGE_SIZE || "1024x1024",
  background: process.env.OPENAI_IMAGE_BACKGROUND || "transparent",
  outputFormat: process.env.OPENAI_IMAGE_FORMAT || "png",
  outputDir: path.resolve(process.cwd(), process.env.OUTPUT_DIR || "output"),
  concurrency: Number.parseInt(process.env.CONCURRENCY || "2", 10),
  maxRetries: Number.parseInt(process.env.MAX_RETRIES || "3", 10),
  requestDelayMs: Number.parseInt(process.env.REQUEST_DELAY_MS || "1200", 10),
  overwrite: process.env.OVERWRITE === "1",
  testMode: process.argv.includes("--test"),
  onlyItems: parseOnlyItems(process.argv),
  customItems: parseCustomItems(process.argv),
  customCategory: parseSingleValueArg(process.argv, "--category="),
};

const promptBase =
  "premium product render, slightly idealized but believable, isolated, transparent background, centered, slight front isometric view, soft refined studio lighting, physically plausible materials, subtle natural surface texture, realistic proportions, flattering realistic shading, accurate object shape, clean silhouette, polished edges, high detail, consistent scale, single object only, fully visible, no text, no label, no logo, no branding, no watermark, no extra objects";

const categoryPromptTemplates = {
  default:
    "Erstelle folgendes Bild: Single {item}, " +
    promptBase,
  food:
    "Erstelle folgendes Bild: Single {item}, premium 3D food render, slightly idealized but believable, isolated, transparent background, centered, slight front isometric view, soft refined studio lighting, physically plausible materials, subtle natural surface texture, realistic proportions, flattering realistic shading, accurate object shape, clean silhouette, polished edges, high detail, consistent scale, single object only, fully visible, no text, no label, no packaging, no plate, no bowl, no cutlery, no extra objects, no duplicate items, no sliced version unless explicitly required, fresh appetizing appearance, not cartoonish",
  drink:
    "Erstelle folgendes Bild: Single {item}, premium 3D beverage render, slightly idealized but believable, isolated, transparent background, centered, slight front isometric view, soft refined studio lighting, physically plausible materials, subtle natural surface texture, realistic proportions, flattering realistic shading, accurate object shape, clean silhouette, polished edges, high detail, consistent scale, single drink item only, fully visible, no text, no label, no logo, no packaging multipack, no extra objects, no straw unless appropriate, no ice cubes unless appropriate, realistic liquid appearance, not cartoonish",
  hygiene:
    "Erstelle folgendes Bild: Single {item}, premium 3D hygiene or personal care product render, slightly idealized but believable, isolated, transparent background, centered, slight front isometric view, soft refined studio lighting, physically plausible materials, subtle natural surface texture, realistic proportions, flattering realistic shading, accurate object shape, clean silhouette, polished edges, high detail, consistent scale, single object only, fully visible, no text, no label, no logo, no branding, no extra objects, premium commercial look, not cartoonish",
  household:
    "Erstelle folgendes Bild: Single {item}, premium 3D household or cleaning product render, slightly idealized but believable, isolated, transparent background, centered, slight front isometric view, soft refined studio lighting, physically plausible materials, subtle natural surface texture, realistic proportions, flattering realistic shading, accurate object shape, clean silhouette, polished edges, high detail, consistent scale, single object only, fully visible, no text, no label, no logo, no branding, no extra objects, premium commercial look, not cartoonish",
  babyPet:
    "Erstelle folgendes Bild: Single {item}, premium 3D baby or pet product render, slightly idealized but believable, isolated, transparent background, centered, slight front isometric view, soft refined studio lighting, physically plausible materials, subtle natural surface texture, realistic proportions, flattering realistic shading, accurate object shape, clean silhouette, polished edges, high detail, consistent scale, single object only, fully visible, no text, no label, no logo, no branding, no extra objects, premium commercial look, not cartoonish",
};

const categoryPromptGroups = {
  food: new Set([
    "Obst & Gemüse",
    "Milch & Käse",
    "Backwaren",
    "Fleisch & Wurst",
    "Tiefkühl",
    "Snacks & Süßes",
    "Konserven & Trocken",
  ]),
  drink: new Set(["Getränke"]),
  hygiene: new Set(["Hygiene & Pflege"]),
  household: new Set(["Haushalt & Reinigung"]),
  babyPet: new Set(["Baby & Tier"]),
};

const repeatedSmallItems = new Set([
  "Erbsen",
  "Pommes",
  "Trauben",
  "Erdbeeren",
  "Gummibärchen",
  "Bonbons",
  "Popcorn",
  "Nüsse",
  "Kichererbsen",
  "Linsen",
  "Bohnen",
  "Cornflakes",
  "Haferflocken",
  "Reis",
  "Spaghetti",
  "Nudeln",
  "Sonnenblumenkerne",
  "Trockenfutter",
  "Cranberrys",
  "Datteln",
  "Erdnussflips",
]);

const itemSpecificPromptAdditions = {
  "Brauner Zucker":
    "show brown sugar: a small tidy mound of moist soft brown sugar crystals, warm caramel-brown color, appetizing, no packaging, no spoon",
  Rohrzucker:
    "show raw cane sugar (Rohrzucker): a small tidy mound of coarse amber-brown cane sugar crystals, appetizing, no packaging, no spoon",
  Schokoladenpudding:
    "show a creamy chocolate pudding dessert, dark glossy brown, a small portion served as a smooth dollop or in a small clear dessert glass, appetizing, no plate, no spoon",
  Vanillepudding:
    "show a creamy vanilla pudding dessert, pale yellow and glossy, a small portion served as a smooth dollop or in a small clear dessert glass, appetizing, no plate, no spoon",
  Vla:
    "show Dutch vla: a thick pourable pale-yellow vanilla dairy custard served in a small clear glass, creamy and smooth, appetizing, no spoon",
  Milchreis:
    "show creamy rice pudding (Milchreis): soft white short-grain rice cooked in milk, a small appetizing portion dusted with cinnamon sugar, served as a dollop or in a small bowl, no spoon",
  "Grießbrei":
    "show creamy semolina pudding (Grießbrei): a smooth pale porridge-like dessert, a small appetizing portion served as a dollop or in a small bowl, a light hint of cinnamon, no spoon",
  "Götterspeise":
    "show fruit jelly dessert (Götterspeise, Wackelpudding): glossy translucent red wobbly jelly, a small molded portion, appetizing, no plate",
  Calamari:
    "show a small appetizing portion of golden-brown deep-fried calamari rings (breaded squid rings), several rings arranged in a tidy little pile as a finished dish, no plate, no bowl",
  Gulasch:
    "show a hearty goulash as a small appetizing portion: chunks of braised beef in a thick reddish-brown sauce, glossy and stew-like, no bowl, no plate",
  Entenbrust:
    "show a single raw duck breast fillet with its skin on, pink-red meat, realistic butcher presentation, no plate",
  Austernsauce:
    "show the oyster sauce in a typical sauce bottle filled with dark glossy brown sauce, with a clean stylized label design and no text; the label clearly depicts oysters; place one or two oysters in the half-shell beside the bottle",
  Insektenspray:
    "show the insect spray in a typical aerosol spray can with a clean stylized label design and no text; the label clearly depicts a mosquito",
  Kirschsaft:
    "show the cherry juice in a typical clear juice bottle filled with deep red juice, with a clean stylized label design and no text; the label clearly depicts cherries; place a few fresh cherries beside the bottle",
  Traubensaft:
    "show the grape juice in a typical clear juice bottle filled with purple juice, with a clean stylized label design and no text; the label clearly depicts grapes; place a small bunch of grapes beside the bottle",
  Tomatensaft:
    "show the tomato juice in a typical clear juice bottle filled with red juice, with a clean stylized label design and no text; the label clearly depicts tomatoes; place a couple of tomatoes beside the bottle",
  "Tonic Water":
    "show the tonic water in a typical clear glass bottle with clear sparkling liquid and a clean stylized label design and no text; the label depicts a lime; place a lime slice beside the bottle",
  Salz:
    "show the salt in a typical spice container or salt shaker with a clean stylized packaging design and no text, place a small pile of coarse salt crystals in front of or beside the container",
  Pfeffer:
    "show the pepper in a typical spice jar with a clean stylized packaging design and no text, place black peppercorns in front of or beside the jar",
  "Paprikapulver edelsüß":
    "show the spice in a typical spice jar with a clean stylized packaging design and no text, place a small mound of fine red paprika powder and one fresh red pepper beside the jar",
  "Paprikapulver rosenscharf":
    "show the spice in a typical spice jar with a clean stylized packaging design and no text, place a small mound of deep red paprika powder and one hot red chili or spicy pepper beside the jar",
  Curry:
    "show the curry in a typical spice jar with a clean stylized packaging design and no text, place a small mound of yellow curry powder in front of the jar",
  Kreuzkümmel:
    "show the cumin in a typical spice jar with a clean stylized packaging design and no text, place cumin seeds in front of or beside the jar",
  Oregano:
    "show the oregano in a typical spice jar with a clean stylized packaging design and no text, place dried oregano leaves and a small oregano sprig beside the jar",
  Basilikum:
    "show the basil in a typical spice jar with a clean stylized packaging design and no text, place dried basil and a few fresh basil leaves beside the jar",
  Thymian:
    "show the thyme in a typical spice jar with a clean stylized packaging design and no text, place dried thyme and a small thyme sprig beside the jar",
  Rosmarin:
    "show the rosemary in a typical spice jar with a clean stylized packaging design and no text, place dried rosemary and a rosemary sprig beside the jar",
  Majoran:
    "show the marjoram in a typical spice jar with a clean stylized packaging design and no text, place dried marjoram leaves beside the jar",
  Zimt:
    "show the cinnamon in a typical spice jar with a clean stylized packaging design and no text, place cinnamon powder and one or two cinnamon sticks beside the jar",
  Muskat:
    "show the nutmeg in a typical spice jar with a clean stylized packaging design and no text, place whole nutmeg and a little nutmeg powder beside the jar",
  Erdbeermarmelade:
    "show the strawberry jam in a typical jam jar with a clean stylized label design and no text, place strawberries and a small visible portion of red jam beside the jar",
  Himbeermarmelade:
    "show the raspberry jam in a typical jam jar with a clean stylized label design and no text, place raspberries and a small visible portion of jam beside the jar",
  Kirschmarmelade:
    "show the cherry jam in a typical jam jar with a clean stylized label design and no text, place cherries and a small visible portion of jam beside the jar",
  Aprikosenmarmelade:
    "show the apricot jam in a typical jam jar with a clean stylized label design and no text, place apricots and a small visible portion of orange jam beside the jar",
  Orangenmarmelade:
    "show the orange marmalade in a typical jam jar with a clean stylized label design and no text, place oranges or orange slices and a small visible portion of marmalade beside the jar",
  Pflaumenmarmelade:
    "show the plum jam in a typical jam jar with a clean stylized label design and no text, place plums and a small visible portion of jam beside the jar",
  Brombeermarmelade:
    "show the blackberry jam in a typical jam jar with a clean stylized label design and no text, place blackberries and a small visible portion of jam beside the jar",
  Johannisbeermarmelade:
    "show the currant jam in a typical jam jar with a clean stylized label design and no text, place currants and a small visible portion of jam beside the jar",
  Heidelbeermarmelade:
    "show the blueberry jam in a typical jam jar with a clean stylized label design and no text, place blueberries and a small visible portion of jam beside the jar",
  Multivitaminmarmelade:
    "show the multivitamin jam in a typical jam jar with a clean stylized label design and no text, place a mixed arrangement of colorful fruits and a small visible portion of jam beside the jar",
  Erdbeerkonfitüre:
    "show the strawberry preserve in a typical jam jar with a clean stylized label design and no text, place strawberries and a small visible portion of preserve beside the jar",
  Himbeerkonfitüre:
    "show the raspberry preserve in a typical jam jar with a clean stylized label design and no text, place raspberries and a small visible portion of preserve beside the jar",
  Aprikosenkonfitüre:
    "show the apricot preserve in a typical jam jar with a clean stylized label design and no text, place apricots and a small visible portion of preserve beside the jar",
  Sauerkirschkonfitüre:
    "show the sour cherry preserve in a typical jam jar with a clean stylized label design and no text, place sour cherries and a small visible portion of preserve beside the jar",
  Quittengelee:
    "show the quince jelly in a typical jam jar with a clean stylized label design and no text, place quince fruit and a glossy portion of jelly beside the jar",
  Apfelgelee:
    "show the apple jelly in a typical jam jar with a clean stylized label design and no text, place apples and a glossy portion of jelly beside the jar",
  Traubengelee:
    "show the grape jelly in a typical jam jar with a clean stylized label design and no text, place grapes and a glossy portion of jelly beside the jar",
  Johannisbeergelee:
    "show the currant jelly in a typical jam jar with a clean stylized label design and no text, place currants and a glossy portion of jelly beside the jar",
  Holunderblütengelee:
    "show the elderflower jelly in a typical jam jar with a clean stylized label design and no text, place elderflower blossoms and a glossy portion of jelly beside the jar",
  Orangengelee:
    "show the orange jelly in a typical jam jar with a clean stylized label design and no text, place oranges or orange slices and a glossy portion of jelly beside the jar",
  Bratwürste:
    "show several raw bratwursts in typical retail tray packaging with a clean stylized packaging design and no text, with one or two sausages placed in front",
  Grillwürste:
    "show several grill sausages in typical retail tray packaging with a clean stylized packaging design and no text, with one or two sausages placed in front",
  Nackensteaks:
    "show raw pork neck steaks in typical retail tray packaging with a clean stylized packaging design and no text, with one steak slightly overlapping in front",
  Hähnchenfilet:
    "show raw chicken breast fillets in typical retail tray packaging with a clean stylized packaging design and no text, with one fillet displayed in front",
  Hähnchenschenkel:
    "show raw chicken legs in typical retail tray packaging with a clean stylized packaging design and no text, with one chicken leg displayed in front",
  Putensteaks:
    "show raw turkey steaks in typical retail tray packaging with a clean stylized packaging design and no text, with one steak displayed in front",
  Bauchfleisch:
    "show raw pork belly slices in typical retail tray packaging with a clean stylized packaging design and no text, with a few slices displayed in front",
  Cevapcici:
    "show several raw cevapcici in typical retail tray packaging with a clean stylized packaging design and no text, with a few pieces arranged in front",
  "Burger Patties":
    "show raw burger patties in typical retail tray packaging with a clean stylized packaging design and no text, with one patty displayed in front",
  Grillkäse:
    "show grill cheese in typical retail packaging with a clean stylized packaging design and no text, with one thick slice displayed in front",
  Halloumi:
    "show halloumi in typical retail packaging with a clean stylized packaging design and no text, with one or two slices displayed in front",
  Lachs:
    "show fresh salmon fillets in typical retail tray packaging with a clean stylized packaging design and no text, with one fillet displayed in front",
  Garnelen:
    "show several shrimp in typical retail tray or bag packaging with a clean stylized packaging design and no text, with a few shrimp arranged in front",
  Maiskolben:
    "show one or two whole corn cobs with a few kernels visible, natural fresh appearance, no plate",
  Paprika:
    "show one whole bell pepper with one cut piece in front, fresh realistic appearance, no plate",
  Zucchini:
    "show one whole zucchini with a few cut slices in front, fresh realistic appearance, no plate",
  Champignons:
    "show several whole button mushrooms with one or two sliced mushrooms in front, fresh realistic appearance, no plate",
  Aubergine:
    "show one whole eggplant with a few cut slices in front, fresh realistic appearance, no plate",
  Zwiebeln:
    "show one or two whole onions with one cut onion half in front, fresh realistic appearance, no plate",
  Kartoffeln:
    "show several whole potatoes with one cut potato half in front, fresh realistic appearance, no plate",
  Baguette:
    "show one whole baguette with several cut slices in front, fresh baked appearance, no plate",
  Kräuterbutter:
    "show herb butter in a typical butter tub or roll wrapper with a clean stylized packaging design and no text, with a small portion of herb butter beside it",
  Grillsoßen:
    "show barbecue sauces in one or two typical squeeze bottles with a clean stylized packaging design and no text, with a small sauce swirl in front",
  Ketchup:
    "show ketchup in a typical squeeze bottle with a clean stylized packaging design and no text, with a small ketchup swirl in front",
  Senf:
    "show mustard in a typical squeeze bottle or jar with a clean stylized packaging design and no text, with a small mustard swirl in front",
  Knoblauchsoße:
    "show garlic sauce in a typical squeeze bottle or tub with a clean stylized packaging design and no text, with a small sauce portion and a garlic bulb beside it",
  "BBQ-Soße":
    "show bbq sauce in a typical bottle with a clean stylized packaging design and no text, with a small dark sauce swirl in front",
  Kräuterquark:
    "show herb quark in a typical dairy tub with a clean stylized packaging design and no text, with a small creamy herb quark portion beside it",
  Tzatziki:
    "show tzatziki in a typical dairy tub with a clean stylized packaging design and no text, with a small creamy tzatziki portion beside it",
  Kartoffelsalat:
    "show potato salad in a typical deli tub with a clean stylized packaging design and no text, with a small visible portion of salad beside it",
  Nudelsalat:
    "show pasta salad in a typical deli tub with a clean stylized packaging design and no text, with a small visible portion of salad beside it",
  Krautsalat:
    "show coleslaw in a typical deli tub with a clean stylized packaging design and no text, with a small visible portion of salad beside it",
  "gemischter Salat":
    "show mixed salad in a typical fresh salad bowl or bag with a clean stylized packaging design and no text, with a visible portion of mixed leaves and vegetables",
  Tomaten:
    "show several whole tomatoes with one cut tomato half in front, fresh realistic appearance, no plate",
  Gurken:
    "show one whole cucumber with a few cut slices in front, fresh realistic appearance, no plate",
  Feta:
    "show a whole feta block with a few cut cubes or slices in front, crumbly white cheese texture clearly visible, no serving board",
  "Burger Buns":
    "show burger buns in a typical bakery bag or package with a clean stylized packaging design and no text, with one bun displayed in front",
  "Hotdog Brötchen":
    "show hot dog buns in a typical bakery bag or package with a clean stylized packaging design and no text, with one bun displayed in front",
  Scheibenkäse:
    "show sliced cheese in a typical retail package with a clean stylized packaging design and no text, with several slices fanned out in front",
  Essiggurken:
    "show pickles in a typical glass jar with a clean stylized label design and no text, with several pickle slices or whole pickles in front",
  Röstzwiebeln:
    "show fried onions in a typical bag or tub with a clean stylized packaging design and no text, with a small pile of crispy fried onions in front",
  Salatblätter:
    "show several fresh lettuce leaves grouped together, crisp natural appearance, no bowl",
  Wassermelone:
    "show one whole watermelon with one large cut wedge in front, fresh juicy appearance, no plate",
  Grillgewürz:
    "show grill seasoning in a typical spice jar with a clean stylized packaging design and no text, with a small mound of seasoning blend in front",
  Marinade:
    "show marinade in a typical bottle or pouch with a clean stylized packaging design and no text, with a small glossy marinade swirl in front",
  Gouda:
    "show a whole gouda cheese wheel or large cheese block with a few cleanly cut slices in front, natural cheese texture, no serving board",
  Edamer:
    "show a whole edam cheese wheel with a few cut slices in front, natural cheese texture, no serving board",
  Emmentaler:
    "show a large whole emmental cheese wheel or block with a few cut slices in front, clearly visible characteristic holes, no serving board",
  Mozzarella:
    "show a whole mozzarella ball together with a few cut slices in front, soft fresh cheese texture, no plate, no garnish",
  Cheddar:
    "show a whole cheddar block with a few cleanly cut slices in front, rich orange cheese color, no serving board",
  Butterkäse:
    "show a whole butterkaese block with a few smooth cut slices in front, mild pale yellow cheese texture, no serving board",
  Camembert:
    "show a whole camembert wheel with one wedge cut out and placed in front, soft white rind clearly visible, no serving board",
  Frischkäse:
    "show a whole tub or package of cream cheese with a clean stylized packaging design and no text, plus a smooth portion of cream cheese beside it",
  Parmesan:
    "show a whole parmesan wedge with a few shaved or cut pieces in front, hard aged cheese texture clearly visible, no serving board",
  Feta:
    "show a whole feta block with a few cut cubes or slices in front, crumbly white cheese texture clearly visible, no serving board",
  Brie:
    "show a whole brie wheel with one wedge cut out and placed in front, soft white rind clearly visible, no serving board",
  Bergkäse:
    "show a whole mountain cheese wheel or large wedge with a few cut slices in front, aged alpine cheese texture clearly visible, no serving board",
  Ziegenkäse:
    "show a whole goat cheese roll or small round cheese with a few cut slices in front, soft white cheese texture clearly visible, no serving board",
  Ricotta:
    "show a whole ricotta tub or container with a clean stylized packaging design and no text, plus a spooned portion of ricotta beside it, no spoon visible",
  Mascarpone:
    "show a whole mascarpone tub or container with a clean stylized packaging design and no text, plus a smooth portion of mascarpone beside it",
  Milch:
    "show the milk in a typical tetra pak carton with a clean stylized packaging design and no text",
  Milchgetränk:
    "show the milk drink in a typical bottle or carton with a clean stylized packaging design and no text",
  Mineralwasser:
    "show the mineral water in a typical bottle with a clean stylized label design and no text",
  Orangensaft:
    "show the orange juice in a typical juice carton or bottle with a clean stylized packaging design and no text, include one whole orange or orange slice next to it",
  Apfelsaft:
    "show the apple juice in a typical juice carton or bottle with a clean stylized packaging design and no text, include one whole apple or apple slice next to it",
  Limonade:
    "show the lemonade in a typical bottle or can with a clean stylized packaging design and no text, include a lemon beside it if it fits naturally",
  Cola:
    "show the cola in a typical bottle or can with a clean stylized packaging design and no text",
  Bier:
    "show the beer in a typical bottle or beer glass with a clean stylized label design and no text",
  Wein:
    "show the wine in a typical wine bottle or elegant wine glass with a clean stylized label design and no text",
  Kaffee:
    "show the coffee in a typical coffee cup or mug, optionally with a small amount of coffee beans beside it",
  Tee:
    "show the tea in a typical cup or mug, optionally with a tea bag or tea leaves beside it",
  Joghurt:
    "show the yogurt in a typical yogurt cup with a clean stylized packaging design and no text",
  Quark:
    "show the quark in a typical dairy cup with a clean stylized packaging design and no text",
  Sahne:
    "show the cream in a typical carton or small bottle with a clean stylized packaging design and no text",
  Eis:
    "show the ice cream in a typical tub, bar, or cone format with a clean stylized packaging design and no text",
  Tiefkühlpizza:
    "show the frozen pizza together with a typical pizza box featuring a clean stylized packaging design and no text",
  Tiefkühlgemüse:
    "show the frozen vegetables in a typical frozen food bag with a clean stylized packaging design and no text",
  Tiefkühlfisch:
    "show the frozen fish in a typical frozen food box or bag with a clean stylized packaging design and no text",
  Fischstäbchen:
    "show the fish sticks together with a typical frozen food box featuring a clean stylized packaging design and no text",
  Chips:
    "show the chips in or spilling slightly from a typical chip bag with a clean stylized packaging design and no text",
  Milchschokolade:
    "show the milk chocolate as a bar with a stylized wrapper design and no text",
  Schokolade:
    "show the chocolate as a bar with a stylized wrapper design and no text",
  Pralinen:
    "show the pralines in a typical premium praline box with a clean stylized packaging design and no text, with a few individual pralines displayed in front",
  Schokoriegel:
    "show the chocolate bar in a typical wrapper with a clean stylized packaging design and no text, with one partially unwrapped bar piece in front",
  Keksriegel:
    "show the cookie bar in a typical wrapper with a clean stylized packaging design and no text, with one cut or partially unwrapped piece in front",
  Waffeln:
    "show the waffles in a typical package with a clean stylized packaging design and no text, with a few waffle pieces displayed in front",
  Knoblauchpulver:
    "show the garlic powder in a typical spice jar with a clean stylized packaging design and no text, place a garlic bulb and a small mound of garlic powder beside the jar",
  Zwiebelpulver:
    "show the onion powder in a typical spice jar with a clean stylized packaging design and no text, place a whole onion and a small mound of onion powder beside the jar",
  Chiliflocken:
    "show the chili flakes in a typical spice jar with a clean stylized packaging design and no text, place red chili flakes and one dried red chili beside the jar",
  Cayennepfeffer:
    "show the cayenne pepper in a typical spice jar with a clean stylized packaging design and no text, place a small mound of cayenne powder and one red chili beside the jar",
  Kurkuma:
    "show the turmeric in a typical spice jar with a clean stylized packaging design and no text, place turmeric powder and a turmeric root beside the jar",
  Ingwer:
    "show the ginger in a typical spice jar with a clean stylized packaging design and no text, place ginger powder and a ginger root beside the jar",
  Kekse:
    "show the cookies in a typical package or sleeve with a clean stylized packaging design and no text, with several cookies displayed in front",
  Doppelkekse:
    "show the sandwich cookies in a typical package with a clean stylized packaging design and no text, with several cookies displayed in front and cream filling visible",
  Butterkekse:
    "show the butter biscuits in a typical package with a clean stylized packaging design and no text, with several biscuits displayed in front",
  Gebäckmischung:
    "show the assorted biscuits in a typical box or bag with a clean stylized packaging design and no text, with a mixed selection of pastry pieces displayed in front",
  Gummibärchen:
    "show the gummy bears in a typical candy bag with a clean stylized packaging design and no text, with multiple colorful gummy bears scattered neatly in front",
  Fruchtgummi:
    "show the fruit gummies in a typical candy bag with a clean stylized packaging design and no text, with multiple colorful fruit gummies displayed in front",
  Weingummi:
    "show the wine gums in a typical candy bag with a clean stylized packaging design and no text, with multiple wine gums displayed in front",
  Lakritz:
    "show the licorice in a typical candy bag or box with a clean stylized packaging design and no text, with several licorice pieces displayed in front",
  Bonbons:
    "show the candies in a typical candy bag or roll with a clean stylized packaging design and no text, with several wrapped or unwrapped candies displayed in front",
  Kaubonbons:
    "show the chewy candies in a typical candy bag or roll with a clean stylized packaging design and no text, with several chewy candies displayed in front",
  Toffees:
    "show the toffees in a typical candy bag or box with a clean stylized packaging design and no text, with several toffees displayed in front",
  Karamellbonbons:
    "show the caramel candies in a typical candy bag or roll with a clean stylized packaging design and no text, with several caramel candies displayed in front",
  Lutscher:
    "show the lollipops in a typical candy bag or small display pack with a clean stylized packaging design and no text, with one or two lollipops placed in front",
  Marshmallows:
    "show the marshmallows in a typical candy bag with a clean stylized packaging design and no text, with several marshmallows displayed in front",
  Kaugummi:
    "show the chewing gum in a typical gum pack or bottle with a clean stylized packaging design and no text, with a few gum pieces displayed in front",
  Dragees:
    "show the dragees in a typical candy box or bag with a clean stylized packaging design and no text, with several colorful dragees displayed in front",
  Brausebonbons:
    "show the fizzy candies in a typical candy roll or bag with a clean stylized packaging design and no text, with several candies displayed in front",
  Kaustreifen:
    "show the chewy strips in a typical candy bag with a clean stylized packaging design and no text, with several strips displayed in front",
  Schokoerdnüsse:
    "show the chocolate peanuts in a typical candy bag with a clean stylized packaging design and no text, with multiple chocolate-coated peanuts displayed in front",
  Schokolinsen:
    "show the chocolate lentils in a typical candy bag or tube with a clean stylized packaging design and no text, with multiple colorful chocolate lentils displayed in front",
  Nougat:
    "show the nougat in a typical confectionery package with a clean stylized packaging design and no text, with a few cut nougat pieces displayed in front",
  Marzipan:
    "show the marzipan in a typical confectionery package with a clean stylized packaging design and no text, with a few cut marzipan pieces displayed in front",
  Geleefrüchte:
    "show the jelly fruits in a typical candy box with a clean stylized packaging design and no text, with several sugar-coated jelly fruit pieces displayed in front",
  Puffreisriegel:
    "show the puffed rice bar in a typical wrapper with a clean stylized packaging design and no text, with one cut or partially unwrapped bar piece in front",
  Allzweckreiniger:
    "show the all-purpose cleaner in a typical spray bottle with a clean stylized packaging design and no text, place a simple countertop or generic cleaned surface detail beside it",
  Badreiniger:
    "show the bathroom cleaner in a typical spray bottle with a clean stylized packaging design and no text, place a bathroom tile or sink detail beside it",
  "WC-Reiniger":
    "show the toilet cleaner in a typical angled toilet cleaner bottle with a clean stylized packaging design and no text, place a simple toilet bowl rim detail beside it",
  Glasreiniger:
    "show the glass cleaner in a typical spray bottle with a clean stylized packaging design and no text, place a glass pane or mirror detail beside it",
  Küchenreiniger:
    "show the kitchen cleaner in a typical spray bottle with a clean stylized packaging design and no text, place a stovetop or kitchen counter detail beside it",
  Bodenreiniger:
    "show the floor cleaner in a typical cleaner bottle with a clean stylized packaging design and no text, place a simple floor tile or wooden floor detail beside it",
  Spülmittel:
    "show the dish soap in a typical dishwashing liquid bottle with a clean stylized packaging design and no text, place a clean plate or soap bubble detail beside it",
  Waschmittel:
    "show the laundry detergent in a typical detergent box or bottle with a clean stylized packaging design and no text, place a folded clean towel or shirt beside it",
  Entkalker:
    "show the descaler in a typical bottle with a clean stylized packaging design and no text, place a kettle or faucet detail beside it",
  Fettlöser:
    "show the degreaser in a typical spray bottle with a clean stylized packaging design and no text, place a stovetop or greasy pan detail beside it",
  Flüssigwaschmittel:
    "show the liquid laundry detergent in a typical bottle with a clean stylized packaging design and no text, place a folded clean shirt or towel beside it",
  Weichspüler:
    "show the fabric softener in a typical bottle with a clean stylized packaging design and no text, place a folded soft towel beside it",
  Fleckenentferner:
    "show the stain remover in a typical bottle or spray bottle with a clean stylized packaging design and no text, place a shirt fabric detail with a subtle stain cue beside it",
  Pulverwaschmittel:
    "show the powdered laundry detergent in a typical detergent box with a clean stylized packaging design and no text, place a scoop of powder and a folded clean towel beside it",
  Cracker:
    "show the crackers with a simple stylized package or sleeve design and no text if packaging is included",
  Riegel:
    "show the bar with a stylized wrapper design and no text",
  Babymilch:
    "show the baby milk in a typical formula tin or carton with a clean stylized packaging design and no text",
  Babynahrung:
    "show the baby food in a typical jar or pouch with a clean stylized packaging design and no text",
  Hundefutter:
    "show the dog food in a typical bag or can with a clean stylized packaging design and no text",
  Katzenfutter:
    "show the cat food in a typical pouch, can, or bowl with a clean stylized packaging design and no text",
  Katzenstreu:
    "show the cat litter in a typical bag with a clean stylized packaging design and no text",
  Hundesnacks:
    "show the dog treats in a typical pouch or bag with a clean stylized packaging design and no text",
  Tiernahrung:
    "show the pet food in a typical bag or can with a clean stylized packaging design and no text",
  Lorbeerblätter:
    "show the bay leaves in a typical spice jar with a clean stylized packaging design and no text, place several dried bay leaves in front of the jar",
};

const items = [
  { category: "Obst & Gemüse", name: "Apfel" },
  { category: "Obst & Gemüse", name: "Banane" },
  { category: "Obst & Gemüse", name: "Orange" },
  { category: "Obst & Gemüse", name: "Zitrone" },
  { category: "Obst & Gemüse", name: "Erdbeeren" },
  { category: "Obst & Gemüse", name: "Trauben" },
  { category: "Obst & Gemüse", name: "Tomate" },
  { category: "Obst & Gemüse", name: "Karotte" },
  { category: "Obst & Gemüse", name: "Gurke" },
  { category: "Obst & Gemüse", name: "Paprika" },
  { category: "Obst & Gemüse", name: "Zwiebel" },
  { category: "Obst & Gemüse", name: "Knoblauch" },
  { category: "Obst & Gemüse", name: "Salat" },
  { category: "Obst & Gemüse", name: "Brokkoli" },
  { category: "Milch & Käse", name: "Milch" },
  { category: "Milch & Käse", name: "Butter" },
  { category: "Milch & Käse", name: "Joghurt" },
  { category: "Milch & Käse", name: "Quark" },
  { category: "Milch & Käse", name: "Sahne" },
  { category: "Milch & Käse", name: "Käse" },
  { category: "Milch & Käse", name: "Gouda" },
  { category: "Milch & Käse", name: "Mozzarella" },
  { category: "Milch & Käse", name: "Eier" },
  { category: "Backwaren", name: "Toastbrot" },
  { category: "Backwaren", name: "Vollkornbrot" },
  { category: "Backwaren", name: "Brot" },
  { category: "Backwaren", name: "Brötchen" },
  { category: "Backwaren", name: "Baguette" },
  { category: "Backwaren", name: "Croissant" },
  { category: "Backwaren", name: "Mehl" },
  { category: "Backwaren", name: "Hefe" },
  { category: "Backwaren", name: "Tortilla" },
  { category: "Fleisch & Wurst", name: "Hähnchenfilet" },
  { category: "Fleisch & Wurst", name: "Hackfleisch" },
  { category: "Fleisch & Wurst", name: "Schweinefilet" },
  { category: "Fleisch & Wurst", name: "Rindfleisch" },
  { category: "Fleisch & Wurst", name: "Lachs" },
  { category: "Fleisch & Wurst", name: "Thunfisch" },
  { category: "Fleisch & Wurst", name: "Salami" },
  { category: "Fleisch & Wurst", name: "Schinken" },
  { category: "Fleisch & Wurst", name: "Würstchen" },
  { category: "Fleisch & Wurst", name: "Aufschnitt" },
  { category: "Tiefkühl", name: "Tiefkühlpizza" },
  { category: "Tiefkühl", name: "Tiefkühlgemüse" },
  { category: "Tiefkühl", name: "Tiefkühlfisch" },
  { category: "Tiefkühl", name: "Fischstäbchen" },
  { category: "Tiefkühl", name: "Eis" },
  { category: "Tiefkühl", name: "Pommes" },
  { category: "Tiefkühl", name: "Spinat" },
  { category: "Tiefkühl", name: "Erbsen" },
  { category: "Getränke", name: "Mineralwasser" },
  { category: "Getränke", name: "Orangensaft" },
  { category: "Getränke", name: "Apfelsaft" },
  { category: "Getränke", name: "Milchgetränk" },
  { category: "Getränke", name: "Limonade" },
  { category: "Getränke", name: "Cola" },
  { category: "Getränke", name: "Bier" },
  { category: "Getränke", name: "Wein" },
  { category: "Getränke", name: "Kaffee" },
  { category: "Getränke", name: "Tee" },
  { category: "Snacks & Süßes", name: "Milchschokolade" },
  { category: "Snacks & Süßes", name: "Schokolade" },
  { category: "Snacks & Süßes", name: "Gummibärchen" },
  { category: "Snacks & Süßes", name: "Chips" },
  { category: "Snacks & Süßes", name: "Kekse" },
  { category: "Snacks & Süßes", name: "Nüsse" },
  { category: "Snacks & Süßes", name: "Popcorn" },
  { category: "Snacks & Süßes", name: "Bonbons" },
  { category: "Snacks & Süßes", name: "Waffeln" },
  { category: "Snacks & Süßes", name: "Riegel" },
  { category: "Snacks & Süßes", name: "Cracker" },
  { category: "Konserven & Trocken", name: "Tomatenmark" },
  { category: "Konserven & Trocken", name: "Dosentomaten" },
  { category: "Konserven & Trocken", name: "Kichererbsen" },
  { category: "Konserven & Trocken", name: "Mais" },
  { category: "Konserven & Trocken", name: "Nudeln" },
  { category: "Konserven & Trocken", name: "Spaghetti" },
  { category: "Konserven & Trocken", name: "Reis" },
  { category: "Konserven & Trocken", name: "Linsen" },
  { category: "Konserven & Trocken", name: "Bohnen" },
  { category: "Konserven & Trocken", name: "Müsli" },
  { category: "Konserven & Trocken", name: "Haferflocken" },
  { category: "Konserven & Trocken", name: "Cornflakes" },
  { category: "Konserven & Trocken", name: "Zucker" },
  { category: "Konserven & Trocken", name: "Salz" },
  { category: "Hygiene & Pflege", name: "Sonnencreme" },
  { category: "Hygiene & Pflege", name: "Wattestäbchen" },
  { category: "Hygiene & Pflege", name: "Taschentücher" },
  { category: "Hygiene & Pflege", name: "Zahnbürste" },
  { category: "Hygiene & Pflege", name: "Handcreme" },
  { category: "Hygiene & Pflege", name: "Zahnpasta" },
  { category: "Hygiene & Pflege", name: "Deodorant" },
  { category: "Hygiene & Pflege", name: "Rasierer" },
  { category: "Hygiene & Pflege", name: "Duschgel" },
  { category: "Hygiene & Pflege", name: "Shampoo" },
  { category: "Haushalt & Reinigung", name: "Geschirrspültabs" },
  { category: "Haushalt & Reinigung", name: "Toilettenpapier" },
  { category: "Haushalt & Reinigung", name: "Küchenrolle" },
  { category: "Haushalt & Reinigung", name: "Spülmittel" },
  { category: "Haushalt & Reinigung", name: "Waschmittel" },
  { category: "Haushalt & Reinigung", name: "Müllbeutel" },
  { category: "Haushalt & Reinigung", name: "Backpapier" },
  { category: "Haushalt & Reinigung", name: "Alufolie" },
  { category: "Haushalt & Reinigung", name: "Schwamm" },
  { category: "Haushalt & Reinigung", name: "Glasreiniger" },
  { category: "Baby & Tier", name: "Babymilch" },
  { category: "Baby & Tier", name: "Babynahrung" },
  { category: "Baby & Tier", name: "Windeln" },
  { category: "Baby & Tier", name: "Hundefutter" },
  { category: "Baby & Tier", name: "Katzenfutter" },
  { category: "Baby & Tier", name: "Katzenstreu" },
  { category: "Baby & Tier", name: "Hundesnacks" },
  { category: "Baby & Tier", name: "Tiernahrung" },
];

function parseOnlyItems(argv) {
  const onlyArg = argv.find((arg) => arg.startsWith("--only="));

  if (!onlyArg) {
    return [];
  }

  return onlyArg
    .slice("--only=".length)
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean);
}

function parseCustomItems(argv) {
  const itemsArg = argv.find((arg) => arg.startsWith("--items="));

  if (!itemsArg) {
    return [];
  }

  return itemsArg
    .slice("--items=".length)
    .split(",")
    .map((value) => value.trim())
    .filter(Boolean);
}

function parseSingleValueArg(argv, prefix) {
  const arg = argv.find((value) => value.startsWith(prefix));
  return arg ? arg.slice(prefix.length).trim() : "";
}

function buildCustomItems(customItemNames, categoryOverride) {
  if (customItemNames.length === 0) {
    return [];
  }

  return customItemNames.map((name) => ({
    category: categoryOverride || "Benutzerdefiniert",
    name,
  }));
}

function normalizeForPrompt(value) {
  return value
    .replaceAll("ae", "ae")
    .replaceAll("oe", "oe")
    .replaceAll("ue", "ue");
}

function filenameFor(itemName) {
  return `${itemName.toLowerCase()}.${config.outputFormat}`;
}

function getPromptTemplate(category) {
  if (categoryPromptGroups.food.has(category)) {
    return categoryPromptTemplates.food;
  }

  if (categoryPromptGroups.drink.has(category)) {
    return categoryPromptTemplates.drink;
  }

  if (categoryPromptGroups.hygiene.has(category)) {
    return categoryPromptTemplates.hygiene;
  }

  if (categoryPromptGroups.household.has(category)) {
    return categoryPromptTemplates.household;
  }

  if (categoryPromptGroups.babyPet.has(category)) {
    return categoryPromptTemplates.babyPet;
  }

  return categoryPromptTemplates.default;
}

function getPromptAdditions(item) {
  const additions = [];

  if (item.category === "Getränke") {
    additions.push(
      "use the product's typical serving vessel or retail packaging, with a stylized packaging design but absolutely no text",
    );
  }

  if (repeatedSmallItems.has(item.name)) {
    additions.push(
      "because this is a small article, show multiple pieces in a tidy grouped arrangement instead of only one tiny piece",
    );
  }

  const specificAddition = itemSpecificPromptAdditions[item.name];
  if (specificAddition) {
    additions.push(specificAddition);
  }

  return additions;
}

function buildPrompt(item) {
  const template = getPromptTemplate(item.category);
  const prompt = template.replace("{item}", normalizeForPrompt(item.name));
  const additions = getPromptAdditions(item);

  if (additions.length === 0) {
    return prompt;
  }

  return `${prompt}, ${additions.join(", ")}`;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function ensureOutputDir() {
  await fs.mkdir(config.outputDir, { recursive: true });
}

async function fileExists(filePath) {
  try {
    await fs.access(filePath);
    return true;
  } catch {
    return false;
  }
}

async function generateImage(prompt, attempt) {
  const response = await fetch("https://api.openai.com/v1/images/generations", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${apiKey}`,
    },
    body: JSON.stringify({
      model: config.model,
      prompt,
      size: config.size,
      background: config.background,
      output_format: config.outputFormat,
    }),
  });

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`HTTP ${response.status} on attempt ${attempt}: ${body}`);
  }

  const json = await response.json();
  const base64 = json?.data?.[0]?.b64_json;

  if (!base64) {
    throw new Error(`No image data returned on attempt ${attempt}.`);
  }

  return Buffer.from(base64, "base64");
}

async function processItem(item, index, total) {
  const outputPath = path.join(config.outputDir, filenameFor(item.name));

  if (!config.overwrite && (await fileExists(outputPath))) {
    console.log(`[skip ${index}/${total}] ${item.name} -> ${path.basename(outputPath)}`);
    return;
  }

  const prompt = buildPrompt(item);
  let lastError;

  for (let attempt = 1; attempt <= config.maxRetries; attempt += 1) {
    try {
      const imageBuffer = await generateImage(prompt, attempt);
      await fs.writeFile(outputPath, imageBuffer);
      console.log(`[ok   ${index}/${total}] ${item.name} -> ${path.basename(outputPath)}`);
      return;
    } catch (error) {
      lastError = error;
      console.error(`[err  ${index}/${total}] ${item.name} attempt ${attempt}: ${error.message}`);
      await sleep(config.requestDelayMs * attempt);
    }
  }

  throw lastError;
}

async function runQueue(workItems) {
  let nextIndex = 0;
  const workers = Array.from({ length: config.concurrency }, async () => {
    while (nextIndex < workItems.length) {
      const current = nextIndex;
      nextIndex += 1;
      await processItem(workItems[current], current + 1, workItems.length);
      await sleep(config.requestDelayMs);
    }
  });

  await Promise.all(workers);
}

function selectWorkItems(allItems) {
  let selectedItems =
    config.customItems.length > 0
      ? buildCustomItems(config.customItems, config.customCategory)
      : allItems;

  if (config.onlyItems.length > 0) {
    const allowedNames = new Set(config.onlyItems.map((itemName) => itemName.toLowerCase()));
    selectedItems = selectedItems.filter((item) => allowedNames.has(item.name.toLowerCase()));
  }

  if (!config.testMode) {
    return selectedItems;
  }

  const seenCategories = new Set();
  return selectedItems.filter((item) => {
    if (seenCategories.has(item.category)) {
      return false;
    }

    seenCategories.add(item.category);
    return true;
  });
}

async function main() {
  await ensureOutputDir();
  const workItems = selectWorkItems(items);

  console.log(`Generating ${workItems.length} images`);
  console.log(`Model: ${config.model}`);
  console.log(`Size: ${config.size}`);
  console.log(`Background: ${config.background}`);
  console.log(`Format: ${config.outputFormat}`);
  console.log(`Output directory: ${config.outputDir}`);
  console.log(`Mode: ${config.testMode ? "test" : "full"}`);
  if (config.onlyItems.length > 0) {
    console.log(`Only items: ${config.onlyItems.join(", ")}`);
  }
  if (config.customItems.length > 0) {
    console.log(`Custom items: ${config.customItems.join(", ")}`);
  }
  if (config.customCategory) {
    console.log(`Custom category: ${config.customCategory}`);
  }

  await runQueue(workItems);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
