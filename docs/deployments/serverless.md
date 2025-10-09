
# Serverless / Edge

Webrick can run in serverless contexts, but mind platform limits.

## Guidance
- **Warm caches**: Prebuild route cache (sharded/fused) during CI and ship with the artifact.
- **Streaming**: Some providers buffer responses; avoid `Response::stream()` on those paths.
- **Signed URLs**: Ensure no mangling of query strings by platform adapters.
- **Large downloads**: Redirect to object storage (S3/GCS) and let Webrick generate signed pre‑signed links if needed.
