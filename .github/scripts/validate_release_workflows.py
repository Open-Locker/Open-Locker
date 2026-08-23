#!/usr/bin/env python3

from pathlib import Path
import json
import tomllib


ROOT = Path(__file__).resolve().parents[2]


def read(relative_path: str) -> str:
    return (ROOT / relative_path).read_text(encoding="utf-8")


def require(text: str, expected: str, source: str) -> None:
    if expected not in text:
        raise AssertionError(f"{source} must contain: {expected}")


def reject(text: str, forbidden: str, source: str) -> None:
    if forbidden in text:
        raise AssertionError(f"{source} must not contain: {forbidden}")


def validate_container_workflow(
    filename: str,
    component_prefix: str,
    image: str,
) -> None:
    source = f".github/workflows/{filename}"
    workflow = read(source)

    require(workflow, f"- '{component_prefix}*'", source)
    require(workflow, "git merge-base --is-ancestor", source)
    require(workflow, "origin/main", source)
    require(workflow, "packages: write", source)
    require(workflow, "contents: write", source)
    require(workflow, "uses: ./.github/workflows/component-release.yml", source)
    require(workflow, f"`{image}:${{{{ github.ref_name }}}}`", source)
    require(
        workflow,
        "type=raw,value=latest,enable=${{ github.ref == 'refs/heads/main' }}",
        source,
    )
    reject(workflow, "locker-client-v", source)
    reject(workflow, "- 'v*'", source)


def validate_mobile_workflow() -> None:
    source = ".github/workflows/mobile-app-build.yml"
    workflow = read(source)

    require(workflow, "- 'mobile-v*'", source)
    require(workflow, "git merge-base --is-ancestor", source)
    require(workflow, 'echo "profile=preview"', source)
    require(workflow, 'echo "profile=store"', source)
    require(
        workflow,
        "if: github.ref == 'refs/heads/main' || startsWith(github.ref, 'refs/tags/mobile-v')",
        source,
    )
    require(workflow, "uses: ./.github/workflows/mobile-app-ci.yml", source)
    require(workflow, "uses: ./.github/workflows/component-release.yml", source)
    reject(workflow, "feat/19-mobile-internal-builds", source)


def validate_git_cliff() -> None:
    expected = {
        "backend": ("^backend-v", ["locker-backend/**"]),
        "client": ("^client-v", ["locker-client/**"]),
        "mobile": ("^mobile-v", ["mobile-app/**"]),
    }

    for component, (tag_prefix, include_paths) in expected.items():
        config_path = ROOT / ".github" / "git-cliff" / f"{component}.toml"
        with config_path.open("rb") as config_file:
            config = tomllib.load(config_file)

        if not config["git"]["tag_pattern"].startswith(tag_prefix):
            raise AssertionError(f"{config_path} has the wrong component tag pattern")
        if config["git"]["include_paths"] != include_paths:
            raise AssertionError(f"{config_path} has the wrong component path filter")

    release_workflow = read(".github/workflows/component-release.yml")
    require(
        release_workflow,
        "orhun/git-cliff-action@f50e11560dce63f7c33227798f90b924471a88b5",
        ".github/workflows/component-release.yml",
    )
    require(
        release_workflow,
        "version: v2.13.1",
        ".github/workflows/component-release.yml",
    )
    require(
        release_workflow,
        "gh release create",
        ".github/workflows/component-release.yml",
    )
    require(
        release_workflow,
        "--verify-tag",
        ".github/workflows/component-release.yml",
    )


def validate_versions() -> None:
    package = json.loads(read("locker-client/package.json"))
    if package["version"] != "1.0.0":
        raise AssertionError("locker-client/package.json must start releases at 1.0.0")


def main() -> None:
    validate_container_workflow(
        "backend-docker.yml",
        "backend-v",
        "ghcr.io/open-locker/locker-backend",
    )
    validate_container_workflow(
        "client-docker.yml",
        "client-v",
        "ghcr.io/open-locker/locker-client",
    )
    validate_mobile_workflow()
    validate_git_cliff()
    validate_versions()
    print("Release workflow invariants are valid.")


if __name__ == "__main__":
    main()
