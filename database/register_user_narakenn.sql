-- ユーザー登録: narakenn1211@gmail.com
-- phpMyAdmin の SQL タブで実行可能

INSERT INTO users (
    email,
    password_hash,
    display_name,
    birth_date,
    status,
    auth_level,
    role
) VALUES (
    'narakenn1211@gmail.com',
    '$2y$10$KElH8i7NQYCwcUFxe/zivuf2FitM/HTuDi6LtgFZQ36pe9r4sY5jS',
    'narakenn',
    '2000-01-01',
    'active',
    1,
    'user'
)
ON DUPLICATE KEY UPDATE 
    password_hash = VALUES(password_hash),
    updated_at = NOW();
