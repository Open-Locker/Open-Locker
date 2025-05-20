# Open-Locker

[![Discord](https://img.shields.io/discord/1330191581273260113?style=flat-square&logo=discord&label=Discord&labelColor=%23FFF)](https://discord.gg/rZ74RYKN3H)

## The Project

This is an open source project to create software that locks and unlocks public lockers to store and/or share items, sponsored by [Smart City Hameln-Pyrmont](https://mitwirkportal.de/informieren).

### What we want to achieve

Within Hameln-Pyrmont, there is a set of lockers that the county uses to lend objects like laptops or VR headset to interested citizens. This project is supposed to improve the user experience and offer the county a way to individualize the software to better suit their needs.

The group came together with the goal to improve their knowledge while building something that will be of immediate use to the people around them.

### How you can help

You can join our weeklies on **Mondays and Tuesdays, alternating every week, at 19:30 CET/18:30 UTC** in our [Discord](https://discord.gg/rZ74RYKN3H), either to listen in or to participate, or you can interact with us via github, sending us pull requests, issues or general feedback. Our next weekly is on Tuesday, the 6th of May, followed by Monday, the 12th of May.

If you're still unsure where to start, you can always reach out to us in our discord's text channels.

### What's going on right now?

At the moment, we're working on an MVP that lets us test the concept with actual users. To achieve this, we've set two smaller goals on the way, for instance a version that allows our hardware team to test their builds with our app. You can check out our current roadmap [here](https://github.com/Open-Locker/Open-Locker/milestones). We'll be updating it as we reach our goals.

## Project Structure and Details

### Monorepo Structure

This project is organized as a monorepo, which means it contains multiple projects within a single repository. Currently, the main project in this repository is:

- `locker-backend`: The backend application built with Laravel.
- `locker_app`: The frontend flutter app.

Using a monorepo allows us to manage all related projects in one place, making it easier to share code and manage dependencies.


### Setting Up Git Hooks

To ensure code quality and consistency, we use Git hooks in this project. Follow these steps to set up the Git hooks:

1. Run the `install-hooks.sh` script to configure the Git hooks path:
    ```sh
    ./install-hooks.sh
    ```

This script will set the Git hooks path to the `.githooks` directory in the project.

2. Verify that the hooks are set up correctly by checking the Git configuration:
    ```sh
    git config core.hooksPath
    ```

You should see `.githooks` as the output.

Now, the Git hooks are configured and will run automatically during the commit process.
