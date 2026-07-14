# Astro Starter Kit: Basics

```sh
pnpm create astro@latest -- --template basics
```

> 🧑‍🚀 **Seasoned astronaut?** Delete this file. Have fun!

## Docker

This project is set up for a **static** Astro build served by **NGINX**.

Build the image:

```sh
docker build -t open-locker-webpage .
```

Run it locally:

```sh
docker run --rm -p 8080:8080 open-locker-webpage
```

Then open `http://localhost:8080`.

## GitLab CI/CD

The pipeline in `.gitlab-ci.yml` builds a Docker image and pushes it to the **GitLab Container Registry**:

- **`$CI_COMMIT_SHA`** tag for immutable builds
- **`$CI_COMMIT_REF_SLUG`** tag for branch builds
- **`latest`** is published on `main`

### Coolify deployment (recommended with Docker Image)

If you run your own **Coolify** instance, you can deploy the pushed image via a Coolify **Docker Image** resource.

1. In Coolify, create a resource of type **Docker Image** and point it to your GitLab registry image, e.g.:
   - `registry.gitlab.com/<group>/<project>:latest`
2. If your registry is private, create a GitLab **Deploy Token** with `read_registry` and configure Coolify to be able to pull the image (Coolify docs describe the required `docker login` on the server).
3. In the Coolify resource, go to **Webhooks** and copy the **Deploy Webhook** URL.
4. Create a Coolify **API token** with deploy permissions.
5. In GitLab, add CI/CD variables:
   - `COOLIFY_WEBHOOK`: the Deploy Webhook URL from Coolify
   - `COOLIFY_TOKEN`: the Coolify API token (mask + protect recommended)

Now every successful `main` pipeline triggers a redeploy in Coolify.

## 🚀 Project Structure

Inside of your Astro project, you'll see the following folders and files:

```text
/
├── public/
│   └── favicon.svg
├── src
│   ├── assets
│   │   └── astro.svg
│   ├── components
│   │   └── Welcome.astro
│   ├── layouts
│   │   └── Layout.astro
│   └── pages
│       └── index.astro
└── package.json
```

To learn more about the folder structure of an Astro project, refer to [our guide on project structure](https://docs.astro.build/en/basics/project-structure/).

## 🧞 Commands

All commands are run from the root of the project, from a terminal:

| Command                   | Action                                           |
| :------------------------ | :----------------------------------------------- |
| `pnpm install`             | Installs dependencies                            |
| `pnpm dev`             | Starts local dev server at `localhost:4321`      |
| `pnpm build`           | Build your production site to `./dist/`          |
| `pnpm preview`         | Preview your build locally, before deploying     |
| `pnpm astro ...`       | Run CLI commands like `astro add`, `astro check` |
| `pnpm astro -- --help` | Get help using the Astro CLI                     |

## SEO (Sitemap + robots.txt)

This site generates a sitemap via `@astrojs/sitemap` and serves a dynamic `robots.txt`.

- **Set `SITE_URL`** in production builds (e.g. `https://example.com`) so canonical URLs, `robots.txt`, and the sitemap use the correct domain.
- If `SITE_URL` is not set, it falls back to `http://localhost:4321` for local development.

## 👀 Want to learn more?

Feel free to check [our documentation](https://docs.astro.build) or jump into our [Discord server](https://astro.build/chat).
