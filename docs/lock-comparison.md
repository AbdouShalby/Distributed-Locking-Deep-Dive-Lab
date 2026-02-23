# Lock Strategy Comparison

## Lock Acquisition & Release Flow

```mermaid
sequenceDiagram
    participant P as Process
    participant L as SafeRedisLock
    participant R as Redis

    Note over P,R: 🟢 Safe Lock: SET NX EX + Lua Release

    P->>L: acquire("product_1", ttl=5000)
    L->>L: token = bin2hex(random_bytes(16))
    L->>R: SET lock:product_1 <token> NX EX 5

    alt Key doesn't exist (lock free)
        R-->>L: OK
        L-->>P: true ✅

        rect rgb(200, 230, 200)
            Note over P: Critical Section
            P->>P: Read stock
            P->>P: Validate stock > 0
            P->>P: Decrement stock
        end

        P->>L: release("product_1")
        L->>R: EVAL "if GET == token then DEL"
        R-->>L: 1 (deleted)
        L-->>P: true ✅

    else Key exists (lock held)
        R-->>L: nil
        L-->>P: false ❌
        Note over P: Retry or fail
    end
```

## Why Each Strategy Fails or Succeeds

```mermaid
graph TD
    subgraph "🔴 NoLock"
        NL1["No coordination"] --> NL2["All processes enter<br/>critical section"]
        NL2 --> NL3["Read-Modify-Write<br/>race condition"]
        NL3 --> NL4["❌ OVERSELLING<br/>stock = -37"]
    end

    subgraph "🟠 NaiveRedisLock"
        NV1["SETNX key 1"] --> NV2["EXPIRE key 5"]
        NV2 --> NV3["💥 Crash between<br/>SETNX and EXPIRE?"]
        NV3 -->|Yes| NV4["❌ Lock without TTL<br/>= permanent deadlock"]
        NV3 -->|No| NV5["DEL key<br/>(no ownership check)"]
        NV5 --> NV6["⚠️ Any process can<br/>release any lock"]
    end

    subgraph "🟢 SafeRedisLock"
        SL1["SET key token NX EX 5<br/>(atomic)"] --> SL2["Token = random_bytes(16)"]
        SL2 --> SL3["Lua: if GET==token<br/>then DEL"]
        SL3 --> SL4["✅ Only owner<br/>can release"]
        SL1 --> SL5["TTL auto-expires<br/>on crash"]
        SL5 --> SL6["✅ No permanent<br/>deadlock"]
    end

    style NL4 fill:#f44336,color:#fff
    style NV4 fill:#f44336,color:#fff
    style NV6 fill:#ff9800,color:#fff
    style SL4 fill:#4caf50,color:#fff
    style SL6 fill:#4caf50,color:#fff
```

## Comparison Summary

| Property | NoLock | NaiveRedisLock | SafeRedisLock |
|----------|:------:|:--------------:|:-------------:|
| **Mutual Exclusion** | ❌ | ✅ (when no crash) | ✅ |
| **Atomic TTL** | — | ❌ (SETNX + EXPIRE gap) | ✅ (SET NX EX) |
| **Ownership** | — | ❌ (no token) | ✅ (random token) |
| **Safe Release** | — | ❌ (plain DEL) | ✅ (Lua compare-and-delete) |
| **Crash Recovery** | — | ❌ (may deadlock) | ✅ (TTL auto-expire) |
| **Production Ready** | ❌ | ❌ | ✅ (single-instance) |
