USE appointment_saas;

-- Password: password
INSERT INTO users
    (name, email, password_hash, created_at, updated_at)
VALUES
    (
        'Rahul Sharma',
        'rahul@example.com',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC4xY7qj2m5mF9nXxQeK',
        NOW(),
        NOW()
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name);

SET @demo_user_id = (
    SELECT id FROM users WHERE email = 'rahul@example.com' LIMIT 1
);

INSERT INTO professional_profiles
    (user_id, name, profession, business_name, phone, address, bio,
     slug, timezone, created_at, updated_at)
VALUES
    (
        @demo_user_id,
        'Rahul Sharma',
        'Consultant',
        'Rahul Sharma Consulting',
        '9876543210',
        'New Delhi, India',
        'Professional consultation by appointment.',
        CONCAT('rahul-sharma-', @demo_user_id),
        'Asia/Kolkata',
        NOW(),
        NOW()
    )
ON DUPLICATE KEY UPDATE
    name = VALUES(name);

INSERT INTO services
    (user_id, name, description, duration_minutes, price,
     is_active, created_at, updated_at)
VALUES
    (
        @demo_user_id,
        'General Consultation',
        'A 30 minute consultation session.',
        30,
        500.00,
        1,
        NOW(),
        NOW()
    ),
    (
        @demo_user_id,
        'Detailed Consultation',
        'A 60 minute consultation session.',
        60,
        900.00,
        1,
        NOW(),
        NOW()
    );

INSERT INTO availability_rules
    (user_id, day_of_week, start_time, end_time, is_available,
     created_at, updated_at)
VALUES
    (@demo_user_id, 0, '09:00:00', '17:00:00', 0, NOW(), NOW()),
    (@demo_user_id, 1, '09:00:00', '17:00:00', 1, NOW(), NOW()),
    (@demo_user_id, 2, '09:00:00', '17:00:00', 1, NOW(), NOW()),
    (@demo_user_id, 3, '09:00:00', '17:00:00', 1, NOW(), NOW()),
    (@demo_user_id, 4, '09:00:00', '17:00:00', 1, NOW(), NOW()),
    (@demo_user_id, 5, '09:00:00', '17:00:00', 1, NOW(), NOW()),
    (@demo_user_id, 6, '09:00:00', '13:00:00', 0, NOW(), NOW())
ON DUPLICATE KEY UPDATE
    start_time = VALUES(start_time),
    end_time = VALUES(end_time),
    is_available = VALUES(is_available);
