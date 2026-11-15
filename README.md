# 📄 README.md pour GitHub

Voici le fichier README complet, prêt à copier-coller dans ton dépôt GitHub :

```markdown
# 🎨 Agnes Style Atlas

> **Bibliothèque visuelle automatique** — Un catalogue encyclopédique qui compare le style de milliers d'artistes sur des sujets neutres identiques (portrait homme+femme, paysage urbain).

[![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://www.php.net/)
[![SQLite](https://img.shields.io/badge/SQLite-3-green)](https://sqlite.org/)
[![License](https://img.shields.io/badge/License-MIT-yellow)](LICENSE)

---

## 🎯 Le concept

**Agnes Style Atlas** est un laboratoire visuel qui répond à une question simple :

> *Comment l'IA Agnes rend-elle le style de chaque artiste du monde ?*

Le site génère automatiquement **2 images par artiste** (un portrait d'homme+femme, un paysage urbain) dans un style cohérent, permettant de **comparer côte à côte** les signatures visuelles de milliers d'artistes — des peintres de la Renaissance aux mangakas contemporains.

Un seul bouton démarre le processus complet : **ajout d'artistes + génération d'images en parallèle**, jusqu'à atteindre l'objectif de 4000 artistes.

---

## ✨ Fonctionnalités

- 🚀 **Un bouton unique** : lance 5 workers images + 1 grower IA en parallèle
- 🎨 **Annuaire visuel** : comparaison côte à côte des styles sur sujets neutres
- 🌱 **Croissance auto** : ajoute 80 artistes toutes les 2-3 minutes via IA (jusqu'à 4000)
- ✍️ **Prompt global** : une ambiance/contexte appliqué à toutes les générations
- ✨ **Optimisation Agnes** : restructure n'importe quel prompt pour maximiser le rendu
- 💾 **Stockage disque** : images JPEG compressées (~200 KB), organisées par artiste
- 🔑 **Rotation de clés API** : gère nativement 10+ clés avec quarantaine auto
- 📊 **Suivi temps réel** : progression, log live, statistiques
- ⭐ **Notation & favoris** : évalue chaque image, marque les meilleures
- 🎭 **Modal détaillée** : clic sur une image → prompt éditable, régénération, copie, téléchargement

---

## 📋 Prérequis

| Composant | Version minimum | Notes |
|---|---|---|
| **PHP** | 8.0+ | Extensions : `pdo_sqlite`, `curl`, `gd` |
| **Serveur web** | Apache / Nginx / PHP built-in | |
| **Espace disque** | ~2-3 GB | Pour 8000 images JPEG 85% |
| **Clés API Agnes** | 1 à 10+ | [Inscription gratuite](https://apihub.agnes-ai.com) |

### Vérifier les extensions PHP

```bash
php -m | grep -E "pdo_sqlite|curl|gd"
```

Si une extension manque :
- **Debian/Ubuntu** : `sudo apt install php-pdo-sqlite php-curl php-gd`
- **WAMP/XAMPP** : activer dans `php.ini` en retirant le `;` devant `extension=pdo_sqlite`, `extension=curl`, `extension=gd`

---

## 🚀 Installation

### 1. Cloner le dépôt

```bash
git clone https://github.com/TON-USER/agnes-style-atlas.git
cd agnes-style-atlas
```

### 2. Déposer sur le serveur web

Copie le fichier `index.php` dans ton dossier web :

```
/var/www/html/agnesklg/index.php
```
ou en local avec WAMP :
```
C:\wamp64\www\agnesklg\index.php
```

### 3. Créer les dossiers (optionnel, auto-créés au premier lancement)

```bash
mkdir -p data images
chmod 755 data images
```

### 4. Lancer dans le navigateur

```
http://localhost/agnesklg/
```

Le site s'initialise : la base SQLite est créée, 50 artistes seed sont insérés.

---

## 🔑 Configuration des clés API

### Obtenir une clé Agnes

1. Créer un compte sur [apihub.agnes-ai.com](https://apihub.agnes-ai.com)
2. Aller dans **API Keys** → **Create key**
3. Copier la clé (format `sk-xxxxxxxxxxxxxxxxxxxxxxxx`)

### Ajouter les clés au site

1. Cliquer sur le panneau **🔑 API KEYS** en bas de page
2. Coller les clés (une par ligne) :
   ```
   sk-xxxxxxxxxxxxxxxxxxxxxxxx
   sk-yyyyyyyyyyyyyyyyyyyyyyyy
   sk-zzzzzzzzzzzzzzzzzzzzzzzz
   ```
3. Cliquer sur **✓ Valider & tester**

Le système teste chaque clé et affiche son statut. Les clés qui échouent 5 fois consécutives sont temporairement mises en quarantaine (auto-récupérées après un reset).

### Fichier de configuration

Les clés sont stockées dans `data/keys.json` :

```json
[
  "sk-xxxxxxxxxxxxxxxxxxxxxxxx",
  "sk-yyyyyyyyyyyyyyyyyyyyyyyy"
]
```

⚠️ **Ce fichier est protégé par `.htaccess`** (créé automatiquement) pour empêcher l'accès direct.

---

## 🎮 Utilisation

### Lancer le catalogue automatique

1. **(Optionnel)** Écrire un **prompt global** dans le champ en haut :
   ```
   atmosphère pluvieuse, tons bleus mélancoliques, ambiance cinématographique
   ```
   
2. Cliquer sur le gros bouton vert **🚀 Lancer le catalogue automatique**

3. **Observer** :
   - Onglet **🎨 Annuaire** : les images apparaissent au fur et à mesure
   - Onglet **🔴 Live** : log temps réel de tout ce qui se passe
   - Barres de progression : croissance artistes + images

4. **Arrêter** : re-cliquer sur le bouton (devenu rouge)

### Éditer le prompt d'une image

1. Cliquer sur n'importe quelle image de l'annuaire
2. La modal s'ouvre avec :
   - Le prompt actuel dans un **textarea éditable**
   - Le compteur de caractères
3. Modifier librement le texte
4. Cliquer sur **✨ Optimiser Agnes** pour restructurer le prompt (recommandé !)
5. Cliquer sur **🔄 Régénérer** pour créer une nouvelle version

### Fonctionnalités de la modal

| Bouton | Action |
|---|---|
| **📋 Copier** | Copie le prompt dans le presse-papiers |
| **✨ Optimiser Agnes** | Restructure selon la méthode Agnes (Sujet → Scène → Style → Lumière → Composition → Qualité) |
| **🔄 Régénérer** | Crée une nouvelle image avec le prompt actuel |
| **⭐ Favori** | Ajoute/retire des favoris |
| **📥 Télécharger** | Télécharge l'image JPEG |

**Raccourcis clavier** : `Esc` fermer · `C` copier

---

## 🏗 Architecture

### Structure des fichiers

```
agnesklg/
├── index.php           ← Fichier unique (PHP + HTML + JS)
├── data/               ← Auto-créé
│   ├── .htaccess       ← Protection
│   ├── agnesref.db     ← Base SQLite
│   ├── keys.json       ← Clés API
│   ├── key_stats.json  ← Compteur d'échecs par clé
│   └── offset.txt      ← Rotation des clés
└── images/             ← Images générées
    ├── Leonard_de_Vinci/
    │   ├── Portrait_homme_et_femme.jpg
    │   └── Paysage_ville.jpg
    ├── Claude_Monet/
    │   ├── Portrait_homme_et_femme.jpg
    │   └── Paysage_ville.jpg
    └── ...
```

### Schéma de la base SQLite

```sql
CREATE TABLE artists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE,          -- Nom de l'artiste
    birth_year INTEGER,        -- Année de naissance
    movement TEXT,             -- Mouvement artistique
    category TEXT,             -- Catégorie (peintre, photographe…)
    batch_id INTEGER,          -- Batch d'ajout (0 = seed)
    created_at TEXT
);

CREATE TABLE concepts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT UNIQUE,          -- Nom du sujet
    category TEXT,
    batch_id INTEGER,
    is_core INTEGER,           -- 1 = sujet principal
    sort_order INTEGER,        -- Ordre d'affichage
    created_at TEXT
);

CREATE TABLE tests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    artist_id INTEGER,
    concept_id INTEGER,
    artist_name TEXT,          -- Dénormalisé pour perf
    concept_name TEXT,         -- Dénormalisé pour perf
    prompt TEXT,               -- Prompt final utilisé
    filename TEXT,             -- Chemin relatif (images/Nom/fichier.jpg)
    status TEXT,               -- pending | done | error
    error TEXT,
    rating INTEGER DEFAULT 0,  -- 0-5
    is_favorite INTEGER DEFAULT 0,
    parent_id INTEGER,         -- Pour les régénérations
    is_regeneration INTEGER DEFAULT 0,
    gen_time_ms INTEGER,
    created_at TEXT
);
```

### Pipeline de génération

```
┌────────────────────────────────────────────────────────┐
│  UTILISATEUR clique sur "Lancer le catalogue"          │
└────────────────┬───────────────────────────────────────┘
                 │
     ┌───────────┴────────────┐
     │                        │
┌────▼─────────────┐  ┌──────▼──────────────────┐
│  GROWER (1x)     │  │  5 IMAGE WORKERS (parallèle)
│  Boucle 30s      │  │  Boucle continue         │
├──────────────────┤  ├──────────────────────────┤
│ if artists<4000: │  │ 1. GET next_batch (3)    │
│   grow(80)       │  │ 2. Pour chaque job :     │
│   → +80 artistes │  │    - POST generate       │
│                  │  │    - Sauvegarde JPEG     │
└──────────────────┘  │    - Log + refresh       │
                      └──────────────────────────┘
                              │
                              ▼
                      ┌───────────────────┐
                      │  IMAGES SUR DISQUE│
                      │  + BDD SQLite     │
                      └───────────────────┘
```

---

## ⚙️ Paramètres configurables

Tous les réglages sont dans le tableau `$CONFIG` en haut de `index.php` :

```php
$CONFIG = [
    // API Agnes
    'base_url'    => 'https://apihub.agnes-ai.com/v1',
    'img_model'   => 'agnes-image-2.5-flash',
    'text_model'  => 'agnes-3.0-flash',
    
    // Timeouts (secondes)
    'img_timeout' => 90,
    'chat_timeout'=> 45,
    
    // Rotation des clés
    'max_fail_before_quarantine' => 5,  // Échecs avant mise en quarantaine
    
    // Compression JPEG
    'compress_max_width' => 1024,       // Largeur max (px)
    'compress_quality'   => 85,         // Qualité (60-95)
    
    // Objectif
    'goal_artists' => 4000,             // Nombre d'artistes cible
];
```

### Ajuster la qualité des images

| Config | Taille moyenne | Qualité visuelle |
|---|---|---|
| `max_width=768, quality=75` | ~100 KB | Ultra-léger, compression visible |
| **`max_width=1024, quality=85`** | **~200 KB** | **Recommandé (invisible)** |
| `max_width=1280, quality=92` | ~450 KB | Haute qualité, quasi identique au PNG |

### Ajuster la vitesse de génération

Le nombre de workers images peut être modifié dans le JavaScript (chercher `STATE.imageWorkers`) :

```javascript
const STATE = {
    imageWorkers: 5,  // ← Modifier ici (2-10 recommandé)
    // ...
};
```

⚠️ **Ne pas dépasser 8 workers** avec 10 clés API — risque de rate limiting côté Agnes.

### Ajuster les sujets

Dans `index.php`, modifier `$CORE_SUBJECTS` :

```php
$CORE_SUBJECTS = [
    ['Portrait homme et femme', 'personne', 1, "a dual portrait of a man and a woman together..."],
    ['Paysage ville', 'lieu', 2, "a cityscape with buildings..."],
    // Ajouter d'autres sujets ici
];
```

⚠️ Après modification, **supprimer `data/agnesref.db`** pour réinitialiser les sujets.

---

## 📊 Performance

### Vitesse attendue (10 clés API)

| Métrique | Valeur |
|---|---|
| Images par minute | ~20-25 |
| Images par heure | ~1200-1500 |
| Artistes ajoutés / 30s | 80 (si < 4000) |
| Temps total (4000 artistes × 2 images) | **~5-6 heures** |
| Taille disque finale (~8000 images) | ~1.6 GB |

### Facteurs limitants

- **Rate limit Agnes** : ~5 RPM par clé gratuite
- **Nombre de workers** : 5-7 optimal avec 10 clés
- **Latence réseau** : ~10-15s par image en moyenne

---

## 🛠 Dépannage

### ❌ « Réponse invalide » ou « Serveur surchargé »

**Cause** : L'API Agnes retourne une page HTML d'erreur (502/503) quand elle est saturée.

**Solution** :
- Baisser le nombre de workers à **3**
- Cliquer sur **🔄 Reset** dans le panneau API pour réinitialiser les compteurs
- Attendre quelques minutes

### ❌ « Aucune clé API »

**Cause** : Le fichier `data/keys.json` est vide ou n'a pas les permissions d'écriture.

**Solution** :
```bash
chmod 755 data
# Vérifier
ls -la data/
```

### ❌ Les images ne s'affichent pas

**Cause** : Le dossier `images/` n'est pas accessible en lecture ou le fichier n'a pas été écrit.

**Solution** :
```bash
chmod -R 755 images/
```

Vérifier avec `index.php?action=disk_usage` :
```json
{"ok":true,"total_mb":2.3,"image_count":12,"avg_kb":190}
```

### ❌ GD non activé

**Cause** : L'extension `php-gd` n'est pas installée, les images ne sont pas compressées (restent en PNG).

**Solution** :
```bash
# Debian/Ubuntu
sudo apt install php-gd
sudo systemctl restart apache2

# WAMP
# Retirer le ; devant extension=gd dans php.ini
```

### ❌ Les clés ne se valident pas

**Cause** : Une ou plusieurs clés sont invalides/expirées.

**Solution** :
1. Vérifier le format (doit commencer par `sk-` et faire 40+ caractères)
2. Tester chaque clé manuellement :
```bash
curl -X POST https://apihub.agnes-ai.com/v1/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer sk-xxx" \
  -d '{"model":"agnes-3.0-flash","messages":[{"role":"user","content":"ok"}],"max_tokens":1}'
```

---

## 🎯 Bonnes pratiques

### Pour un catalogue complet

1. Utiliser **au moins 5 clés API** (idéalement 10)
2. Laisser tourner **6-8 heures** pour atteindre les 4000 artistes
3. Vérifier le disque : `du -sh images/` doit être < 5 GB
4. Noter les images en temps réel pour garder les meilleures

### Pour tester un prompt global

1. Lancer avec seulement **10-20 artistes** en objectif (modifier `goal_artists`)
2. Tester le prompt global sur ces artistes
3. Ajuster selon les résultats
4. Relancer à pleine échelle

### Pour économiser l'espace disque

```php
// Compresser davantage
'compress_max_width' => 768,
'compress_quality'   => 75,
```

Divise la taille par 2 (~100 KB/image au lieu de 200 KB).

---

## 🗺 Roadmap

- [x] v1 : Génération basique img→txt→img
- [x] v2 : Pipeline parallèle
- [x] v3 : Compression JPEG
- [x] v4 : Auto-grow IA
- [x] v5 : Sujets fixes neutres
- [x] v6 : Prompt global
- [x] v7 : Optimisation Agnes
- [ ] **v8** : Export ZIP par artiste
- [ ] **v9** : Multi-langue (EN/ES/ZH)
- [ ] **v10** : Mode "compare" (2 artistes côte à côte)
- [ ] **v11** : Statistiques avancées (heatmap de similarité)

---

## 🤝 Contribuer

Les contributions sont les bienvenues !

1. Fork le projet
2. Créer une branche (`git checkout -b feature/ma-feature`)
3. Commit (`git commit -am 'Ajoute ma feature'`)
4. Push (`git push origin feature/ma-feature`)
5. Ouvrir une Pull Request

---

## 📜 Licence

MIT © [Ton nom](https://github.com/TON-USER)

---

## 🙏 Remerciements

- **Agnes AI** pour l'API de génération d'images
- **La communauté open source** pour PHP, SQLite, GD

---

## 📚 Documentation liée

- [Guide des prompts Agnes](https://docs.agnes-ai.com/prompts)
- [API Reference](https://docs.agnes-ai.com/api)
- [Rate Limits](https://docs.agnes-ai.com/rate-limits)

---

## ⚠️ Avertissement

Ce projet est un **outil de recherche visuelle et de comparaison de styles**. Les images générées sont des **interprétations par IA** et ne sont **pas des œuvres originales** des artistes référencés. Respecter les droits d'auteur et les conditions d'utilisation des œuvres originales.

**Ne pas utiliser commercialement** les images générées dans le style d'artistes vivants sans leur autorisation.

---

<p align="center">
  <b>⭐ Si ce projet t'aide, laisse une étoile ! ⭐</b><br>
  <i>Construit avec ❤️ pour explorer la diversité des styles visuels</i>
</p>
```

---

## 📌 Comment l'utiliser sur GitHub

1. **Va sur ton dépôt GitHub** (dans le navigateur)
2. Clique sur **"Add file"** → **"Create new file"**
3. Nomme le fichier **`README.md`**
4. **Colle le contenu** ci-dessus
5. Clique sur **"Commit changes"**

Le README s'affichera automatiquement en page d'accueil de ton dépôt avec le formatage markdown. 🎉

### Bonus : ajouter une LICENSE

Si tu veux MIT, crée un fichier `LICENSE` et colle :

```
MIT License

Copyright (c) 2025 [Ton Nom]

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```
