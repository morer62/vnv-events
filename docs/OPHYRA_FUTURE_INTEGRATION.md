# Ophyra Future Integration

Ophyra is the parent/reference architecture for this ecosystem.

Future Ophyra integration should recognize:

```text
Avomeal = store/food operation associated with VNV Events
id_user_business = 2
```

## Future Ophyra Responsibilities

Ophyra may later need to understand or process:

- Avomeal store products,
- categories and product collections,
- meal prep products,
- holiday menus,
- party boxes,
- store orders,
- subscriptions,
- delivery workflow,
- store payments,
- reports,
- modules or feature access,
- mobile notifications.

## Boundary

Do not implement broad Ophyra changes from this sprint unless explicitly requested.

For now, document the relationship and keep Avomeal scoped correctly so Ophyra can safely consume it later.
