# Race Condition Deep Dive

## The Read-Modify-Write Problem

```mermaid
sequenceDiagram
    participant PA as Process A
    participant R as Redis (stock)
    participant PB as Process B

    Note over PA,PB: Stock = 1, Both processes try to purchase

    PA->>R: GET stock
    R-->>PA: stock = 1

    PB->>R: GET stock
    R-->>PB: stock = 1

    Note over PA,PB: ⚠️ Both see stock = 1 (stale read)

    PA->>PA: Check: 1 > 0? ✅
    PB->>PB: Check: 1 > 0? ✅

    PA->>R: SET stock = 0
    Note over R: stock = 0

    PB->>R: SET stock = 0
    Note over R: stock = 0 (lost update!)

    Note over PA,PB: 🔴 Both processes "succeeded"<br/>but stock was only 1!<br/>= OVERSELLING
```

## With Safe Lock: Only One Enters

```mermaid
sequenceDiagram
    participant PA as Process A
    participant L as Redis Lock
    participant R as Redis (stock)
    participant PB as Process B

    Note over PA,PB: Stock = 1, Safe lock prevents race

    PA->>L: SET lock:prod token_A NX EX 5
    L-->>PA: OK ✅

    PB->>L: SET lock:prod token_B NX EX 5
    L-->>PB: nil ❌ (key exists)

    Note over PB: Lock held by A → rejected

    PA->>R: GET stock
    R-->>PA: stock = 1
    PA->>PA: Check: 1 > 0? ✅
    PA->>R: DECRBY stock 1
    R-->>PA: stock = 0

    PA->>L: EVAL Lua: DEL if token matches
    L-->>PA: released ✅

    Note over PA,PB: 🟢 Only Process A succeeded<br/>Stock = 0 (correct)
```

## Timing Diagram: Why Fork Creates Real Races

```mermaid
gantt
    title 10 Processes via pcntl_fork (No Lock)
    dateFormat X
    axisFormat %Lms

    section Process 0
    GET stock=1    :a1, 0, 2
    SET stock=0    :a2, 7, 9

    section Process 1
    GET stock=1    :b1, 0, 2
    SET stock=0    :b2, 7, 9

    section Process 2
    GET stock=1    :c1, 1, 3
    SET stock=0    :c2, 7, 9

    section Process 3
    GET stock=1    :d1, 1, 3
    SET stock=0    :d2, 8, 10

    section Process 4
    GET stock=1    :e1, 1, 3
    SET stock=0    :e2, 8, 10

    section Result
    All read stock=1   :crit, r1, 0, 3
    All decrement       :crit, r2, 7, 10
```

## Key Insight

> **Without locking, the gap between READ and WRITE is the vulnerability window.**
> Every process that reads during this window gets a stale value.
> With `pcntl_fork()`, all children start simultaneously — maximizing the race window.
