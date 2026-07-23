import fs from "node:fs/promises";
import path from "node:path";

const apiKey = process.env.GEMINI_API_KEY;

if (!apiKey) {
  console.error("GEMINI_API_KEY is not set.");
  process.exit(1);
}

const config = {
  model: process.env.GEMINI_IMAGE_MODEL || "gemini-2.5-flash-image",
  outputDir: path.resolve(process.cwd(), process.env.OUTPUT_DIR || "output-gemini"),
  concurrency: Number.parseInt(process.env.CONCURRENCY || "2", 10),
  maxRetries: Number.parseInt(process.env.MAX_RETRIES || "4", 10),
  requestDelayMs: Number.parseInt(process.env.REQUEST_DELAY_MS || "1500", 10),
  overwrite: process.env.OVERWRITE === "1",
  testMode: process.argv.includes("--test"),
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

function buildPrompt(item) {
  const template = getPromptTemplate(item.category);
  return template.replace("{item}", item.name);
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

function sanitizeBaseName(itemName) {
  return itemName.toLowerCase();
}

function extensionFromMimeType(mimeType) {
  if (mimeType === "image/png") {
    return "png";
  }

  if (mimeType === "image/webp") {
    return "webp";
  }

  if (mimeType === "image/jpeg") {
    return "jpg";
  }

  return "bin";
}

function candidateOutputPaths(itemName) {
  const baseName = sanitizeBaseName(itemName);
  return ["png", "jpg", "webp", "bin"].map((extension) =>
    path.join(config.outputDir, `${baseName}.${extension}`),
  );
}

async function hasAnyExistingOutput(itemName) {
  const outputPaths = candidateOutputPaths(itemName);

  for (const outputPath of outputPaths) {
    if (await fileExists(outputPath)) {
      return true;
    }
  }

  return false;
}

async function generateImage(prompt, attempt) {
  const response = await fetch(
    `https://generativelanguage.googleapis.com/v1beta/models/${config.model}:generateContent`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "x-goog-api-key": apiKey,
      },
      body: JSON.stringify({
        contents: [
          {
            parts: [{ text: prompt }],
          },
        ],
      }),
    },
  );

  if (!response.ok) {
    const body = await response.text();
    throw new Error(`HTTP ${response.status} on attempt ${attempt}: ${body}`);
  }

  const json = await response.json();
  const parts = json?.candidates?.[0]?.content?.parts ?? [];
  const imagePart = parts.find((part) => part.inlineData?.data || part.inline_data?.data);

  if (!imagePart) {
    throw new Error(`No image data returned on attempt ${attempt}.`);
  }

  const inlineData = imagePart.inlineData ?? imagePart.inline_data;
  return {
    buffer: Buffer.from(inlineData.data, "base64"),
    mimeType: inlineData.mimeType ?? inlineData.mime_type ?? "application/octet-stream",
  };
}

async function processItem(item, index, total) {
  if (!config.overwrite && (await hasAnyExistingOutput(item.name))) {
    console.log(`[skip ${index}/${total}] ${item.name}`);
    return;
  }

  const prompt = buildPrompt(item);
  let lastError;

  for (let attempt = 1; attempt <= config.maxRetries; attempt += 1) {
    try {
      const result = await generateImage(prompt, attempt);
      const extension = extensionFromMimeType(result.mimeType);
      const fileName = `${sanitizeBaseName(item.name)}.${extension}`;
      const outputPath = path.join(config.outputDir, fileName);
      await fs.writeFile(outputPath, result.buffer);
      console.log(`[ok   ${index}/${total}] ${item.name} -> ${fileName}`);
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
  if (!config.testMode) {
    return allItems;
  }

  const seenCategories = new Set();
  return allItems.filter((item) => {
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
  console.log(`Output directory: ${config.outputDir}`);
  console.log(`Mode: ${config.testMode ? "test" : "full"}`);
  console.log("Note: Gemini image outputs may not contain a true alpha-transparent background.");

  await runQueue(workItems);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
