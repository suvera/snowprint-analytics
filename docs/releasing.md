# Releasing Snowprint

Images are built and pushed to Docker Hub (`suvera/snowprint`) from a maintainer's machine
with [`docker/build.sh`](../docker/build.sh), the same way Winter Boot publishes
`suvera/winter-boot`. CI only tests; it never publishes.

## Cutting a release

1. Set the version, without a `v`, in both places:
   - `config/application.yml`: `winter.application.version: 0.1.0`
   - `deploy/helm/Chart.yaml`: `appVersion: "0.1.0"`, and raise the chart's own `version`
     when the chart changed.
2. In `CHANGELOG.md`, rename `[Unreleased]` to `[0.1.0] - YYYY-MM-DD` and start a new empty
   `[Unreleased]` section.
3. Commit, wait for CI to pass, then tag and push the tag:

   ```bash
   git tag -a v0.1.0 -m "Snowprint 0.1.0"
   git push origin v0.1.0
   ```

4. Build and push the image (needs `docker login` as an account that can push to
   `suvera/snowprint`, once per machine):

   ```bash
   SNOWPRINT_IMAGE=suvera/snowprint docker/build.sh --push
   ```

   It builds `suvera/snowprint:0.1.0` and `:latest` for your machine's architecture and
   pushes both. For `suvera/snowprint` it refuses `-dev` versions and a dirty working tree.
   `SNOWPRINT_IMAGE` is needed when `deploy/values.local.yaml` exists, because the script
   otherwise pushes to the registry your own cluster pulls from.

Afterwards set the version in `config/application.yml` and `appVersion` to the next
development version (for example `0.2.0-dev`).
