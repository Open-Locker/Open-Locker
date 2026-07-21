# Open Locker Website

The marketing / landing page for the Open-Locker project, served at
[open-locker.org](https://open-locker.org/). A static site built with
[Astro](https://astro.build) (MDX, sitemap, Tailwind CSS 4), hosted on
**GitHub Pages**.

Imported from the former standalone GitLab repository
(`merona-merlin/open-locker-website`) with full history — see issue #53.

## Local development

Requires Node 22+ and pnpm 11 (`corepack enable`).

```sh
pnpm install
pnpm dev        # dev server at http://localhost:4321
```

| Command        | Action                                             |
| :------------- | :------------------------------------------------- |
| `pnpm install` | Install dependencies                               |
| `pnpm dev`     | Start local dev server at `localhost:4321`         |
| `pnpm build`   | Build the production site to `./dist/`             |
| `pnpm preview` | Serve the built `dist/` locally                    |
| `pnpm astro …` | Run Astro CLI commands (`astro add`, `astro check`) |

## Project structure

```text
website/
├── public/          # static assets served as-is (logos, images, favicons)
├── src/
│   ├── pages/       # routes (index, blog, impressum, datenschutz, …)
│   ├── content/     # blog posts (MDX content collections)
│   ├── components/  # Astro components
│   ├── layouts/     # shared layouts
│   ├── config/      # site configuration
│   └── styles/      # global styles (Tailwind)
└── astro.config.mjs
```

## Deployment (GitHub Pages)

Deployment is automated by [`.github/workflows/deploy-website.yml`](../.github/workflows/deploy-website.yml):
every push to `main` that touches `website/**` builds the site and publishes
`dist/` to GitHub Pages, which serves it at **https://open-locker.org** (the
custom domain configured in the repository's Pages settings; DNS A records
point the domain at GitHub Pages). There is no server to maintain — no Docker,
no nginx.

The workflow can also be run manually from the Actions tab (*Deploy Website* →
*Run workflow*).

### Build-time environment

| Variable   | Purpose                                                  | Production value          |
| :--------- | :------------------------------------------------------- | :------------------------ |
| `SITE_URL` | Canonical origin for URLs, `robots.txt`, and the sitemap | `https://open-locker.org` |

It defaults to `http://localhost:4321` when unset.
