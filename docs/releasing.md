# Releasing Snowprint

Images are published to Docker Hub as `suvera/snowprint` by
[`.github/workflows/release.yml`](../.github/workflows/release.yml) when a version tag is
pushed. Nothing is published from a laptop.

## One-time setup

In the GitHub repository settings, add the secrets `DOCKERHUB_USERNAME` and
`DOCKERHUB_TOKEN` (a Docker Hub access token with write access to the image). To publish
somewhere else, set the repository variable `DOCKERHUB_IMAGE` (for example
`yourname/snowprint`).

## Cutting a release

1. Set the version, without a `v`, in both places:
   - `config/application.yml`: `winter.application.version: 0.1.0`
   - `deploy/helm/Chart.yaml`: `appVersion: "0.1.0"`, and raise the chart's own `version`
     when the chart changed.
2. In `CHANGELOG.md`, rename `[Unreleased]` to `[0.1.0] - YYYY-MM-DD` and start a new empty
   `[Unreleased]` section.
3. Commit, wait for CI to pass, then tag and push:

   ```bash
   git tag -a v0.1.0 -m "Snowprint 0.1.0"
   git push origin v0.1.0
   ```

The workflow then:

1. checks that the tag, `winter.application.version` and `appVersion` are the same;
2. runs `tests/e2e.sh`;
3. builds the image on native amd64 and arm64 runners and pushes `0.1.0-amd64` and
   `0.1.0-arm64`;
4. publishes the multi-arch tags `0.1.0`, `0.1` and `latest`. A pre-release such as
   `v0.2.0-rc.1` gets only its own tag.

Afterwards set the version in `config/application.yml` and `appVersion` to the next
development version (for example `0.2.0-dev`).

`docker/build.sh --push` still builds and pushes a single-architecture image from your
machine, for private registries such as the one your own cluster pulls from.
