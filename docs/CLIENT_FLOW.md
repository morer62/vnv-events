# Client Flow

Avomeal customers are final customers, normally Level 5 users.

They are not Ophyra business accounts and should not activate SaaS modules.

## Scope

```text
id_user_business = 2
id_owner = 2
```

## Customer Capabilities

Level 5 customers may:

- register or be created during checkout,
- place Avomeal orders,
- view their Store orders,
- view Store order details,
- view subscriptions when enabled,
- access nutrition or wellness tools when available,
- use chat only if the business enables that flow.

## Boundaries

Level 5 customers must not see:

- Level 1 admin screens,
- team workspaces,
- global VNV Events management tools,
- Ophyra module/billing administration,
- data from other businesses.

If checkout creates a Level 5 user automatically, that user must stay attached to owner `2`.
