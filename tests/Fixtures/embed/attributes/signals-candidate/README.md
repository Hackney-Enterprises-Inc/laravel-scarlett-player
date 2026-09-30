# Signals candidate embed attribute contract

#### Analytics Attributes (Full build)

| Attribute | Type | Description |
|-----------|------|-------------|
| `data-analytics-beacon-url` | string | Beacon endpoint. Setting it enables the analytics plugin |
| `data-analytics-video-id` | string | Video identifier sent with every beacon |
| `data-analytics-api-key` | string | Optional API key |
| `data-analytics-anonymous` | boolean | Use per-view anonymous identifiers without persistent storage; default `false` |
| `data-analytics-respect-dnt` | boolean | Respect browser Do Not Track / Global Privacy Control and suppress beacons; default `false` |
| `data-analytics-batch` | boolean | Opt in to batch requests; default `false`. Requires an endpoint that accepts `{ batch: 1, sentAt, events: [...] }` rather than single-beacon bodies |

