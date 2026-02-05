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

The `deploy` job is included as a **manual placeholder** and should be replaced with your real deployment logic.

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

## 👀 Want to learn more?

Feel free to check [our documentation](https://docs.astro.build) or jump into our [Discord server](https://astro.build/chat).
