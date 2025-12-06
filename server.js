import express from 'express';
import cors from 'cors';
import { nanoid } from 'nanoid';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.static(__dirname));

// In-memory database
const urlDatabase = new Map();

// Routes
app.post('/api/shorten', (req, res) => {
    const { originalUrl } = req.body;

    if (!originalUrl) {
        return res.status(400).json({ error: 'URL is required' });
    }

    try {
        new URL(originalUrl); // Validate URL
    } catch (err) {
        return res.status(400).json({ error: 'Invalid URL format' });
    }

    const shortId = nanoid(8);
    urlDatabase.set(shortId, originalUrl);

    // In a real deployed app, this would be the actual domain
    const shortUrl = `${req.protocol}://${req.get('host')}/${shortId}`;

    res.json({ shortUrl, shortId });
});

app.get('/:code', (req, res) => {
    const { code } = req.params;
    const originalUrl = urlDatabase.get(code);

    if (originalUrl) {
        res.redirect(originalUrl);
    } else {
        res.status(404).sendFile(path.join(__dirname, 'index.html')); // Or a 404 page
    }
});

app.listen(PORT, () => {
    console.log(`Server is running on http://localhost:${PORT}`);
});
