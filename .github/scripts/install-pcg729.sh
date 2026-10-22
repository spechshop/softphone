#!/usr/bin/env bash
set -euo pipefail

repo=spechshop/pcg729
api="https://api.github.com/repos/${repo}/releases"
case "${PCG729_RELEASE:-stable}" in
  stable) endpoint="$api/latest" ;;
  prerelease) endpoint="$api?per_page=100" ;;
  *) endpoint="$api/tags/${PCG729_RELEASE}" ;;
esac

auth=()
if [[ -n "${GH_TOKEN:-}" ]]; then auth=(-H "Authorization: Bearer $GH_TOKEN"); fi
response=$(curl --fail --silent --show-error --location \
  -H "Accept: application/vnd.github+json" "${auth[@]}" \
  -H 'X-GitHub-Api-Version: 2022-11-28' "$endpoint")
if [[ "${PCG729_RELEASE:-stable}" == prerelease ]]; then
  release=$(jq -c '[.[] | select(.draft == false and .prerelease == true)] | sort_by(.published_at) | last' <<<"$response")
else
  release=$(jq -c 'if type == "array" then .[0] else . end' <<<"$response")
fi
[[ "$release" != null ]] || { echo 'No suitable pcg729 release found' >&2; exit 1; }
if [[ "${PCG729_RELEASE:-stable}" == stable ]] && { [[ $(jq -r .draft <<<"$release") != false ]] || [[ $(jq -r .prerelease <<<"$release") != false ]]; }; then
  echo 'GitHub latest endpoint did not return a stable release' >&2; exit 1
fi
tag=$(jq -er '.tag_name' <<<"$release")
asset=$(jq -cer '[.assets[] | select(.name == "php-xmlwriter")] | if length == 1 then .[0] else error("expected exactly one php-xmlwriter asset") end' <<<"$release")
asset_id=$(jq -er '.id' <<<"$asset")
asset_url=$(jq -er '.browser_download_url' <<<"$asset")

arch=$(uname -m)
os=$(uname -s)
[[ "$os" == Linux ]] || { echo "Unsupported runner OS: $os" >&2; exit 1; }
case "$arch" in x86_64|aarch64|arm64) ;; *) echo "Unsupported runner architecture: $arch" >&2; exit 1 ;; esac

install_dir="${RUNNER_TEMP:-${TMPDIR:-/tmp}}/pcg729/${tag}"
mkdir -p "$install_dir"
php_bin="$install_dir/php-xmlwriter"
curl --fail --silent --show-error --location \
  -H 'Accept: application/octet-stream' "$asset_url" -o "$php_bin"
test -s "$php_bin"
chmod 0755 "$php_bin"
file "$php_bin"
sha=$(sha256sum "$php_bin" | awk '{print $1}')

# A wrong-architecture executable fails here. Do not fall back to runner PHP.
"$php_bin" -v
for extension in swoole bcg729 opus psampler xmlwriter; do "$php_bin" --ri "$extension" >/dev/null; done
"$php_bin" -r '$required = ["Swoole\\Coroutine\\Socket", "Swoole\\Table", "Swoole\\Timer", "bcg729Channel", "opusChannel", "Resampler", "LPCM", "PcmBuffer", "XMLWriter"]; foreach ($required as $class) { if (!class_exists($class)) { fwrite(STDERR, "Missing required runtime class: $class\n"); exit(1); } } foreach (["decodePcmaToPcm", "decodePcmuToPcm", "encodePcmToPcma", "encodePcmToPcmu", "decodeL16ToPcm", "encodePcmToL16", "mixAudioChannels", "pcmLeToBe", "resampler"] as $function) { if (!function_exists($function)) { fwrite(STDERR, "Missing required runtime function: $function\n"); exit(1); } } foreach ([["Swoole\\Coroutine\\Socket", "bind"], ["Swoole\\Coroutine\\Socket", "recvfrom"], ["Swoole\\Coroutine\\Socket", "sendto"], ["Swoole\\Coroutine\\Socket", "getsockname"], ["Swoole\\Table", "create"], ["Swoole\\Table", "get"], ["Swoole\\Timer", "tick"], ["bcg729Channel", "decode"], ["bcg729Channel", "encode"], ["opusChannel", "decode"], ["opusChannel", "encode"], ["Resampler", "sample"], ["LPCM", "encodeMono"], ["PcmBuffer", "append"], ["XMLWriter", "openMemory"]] as [$class, $method]) { if (!method_exists($class, $method)) { fwrite(STDERR, "Missing required runtime API: $class::$method\n"); exit(1); } }'

if [[ -n "${GITHUB_ENV:-}" ]]; then
  {
    echo "PCG729_TAG=$tag"
    echo "PCG729_ASSET_ID=$asset_id"
    echo "PCG729_SHA256=$sha"
    echo "PCG729_PHP=$php_bin"
  } >> "$GITHUB_ENV"
  echo "$php_bin" >> "${GITHUB_PATH:?}"
  {
    echo '### pcg729 runtime'
    echo "- Release: `$tag`"
    echo "- Asset: `php-xmlwriter` (id `$asset_id`)"
    echo "- SHA-256: `$sha`"
    echo "- PHP: `$($php_bin -r 'echo PHP_VERSION;')`"
    echo "- Swoole: `$($php_bin --ri swoole | awk -F ' => ' '/^Version =>/{print $2; exit}')`"
    for extension in bcg729 opus psampler xmlwriter; do
      extension_version=$($php_bin --ri "$extension" | awk -F ' => ' '/(Version|support) =>/{print $2; exit}')
      echo "- $extension: `$extension_version`"
    done
    echo "- Architecture: `$os/$arch`"
  } >> "${GITHUB_STEP_SUMMARY:?}"
fi
echo "PCG729_TAG=$tag PCG729_ASSET_ID=$asset_id PCG729_SHA256=$sha PCG729_PHP=$php_bin"
