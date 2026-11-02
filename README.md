# Shared EV Charging Stations

A web platform for **sharing private EV chargers in residential buildings**, with a **constraint-based recommendation engine** that finds each driver the best available station by distance, price and rating.

B.Sc. final project in Industrial Engineering & Management (Information Systems track) at Afeka Tel Aviv Academic College of Engineering, built in collaboration with **EvEnergy**.

> The interface is in Hebrew (RTL).

## The problem

The EV market is growing faster than public charging infrastructure, especially in cities and shared residential buildings. At the same time, many private chargers sit idle most of the day.

## The solution

A web system that connects private charger owners with EV drivers who need to charge:

- **Owners** list their chargers, set prices and opening hours, and approve or decline bookings.
- **Drivers** find stations on an interactive map, get personal recommendations, book in advance, charge and leave a rating.

## Key features

- Sign-up and login, personal area and charging preferences
- Interactive map with color-coded availability (Leaflet + OpenStreetMap) and Waze navigation
- Search, favorites and **top-3 recommended stations**
- Advance booking with owner approval, a charging timer and automatic cost calculation
- Ratings, plus in-app and email notifications
- **EvBot**, an AI assistant for operational questions (Google Gemini API)

## How the recommendation engine works

`recommended_stations.php` treats the user's charging preferences as constraints:

1. **Hard constraints:** keep only stations that are available right now, then filter by maximum distance, maximum price and minimum rating. If fewer than 3 stations pass a step, that constraint is relaxed to the 3 best stations on that criterion.
2. **Scoring:** distance, price and rating are min-max normalized and summed into a single score (lower is better).
3. **Result:** the 3 best-scoring stations are shown to the user on the map.

## Results

- An existing user can find, view and book a station in **3 clicks or fewer**
- Basic sign-up takes up to 9 clicks; extended sign-up (payment details and preferences) up to 16
- The recommendation engine returns the 3 most relevant stations for each user

## Tech stack

PHP · MySQL (phpMyAdmin, WampServer) · HTML / CSS / JavaScript · Leaflet + OpenStreetMap · Waze deep links · PHPMailer (Gmail SMTP) · Google Gemini API

## Database

A relational MySQL database with 10 tables: users, stations, owner stations, orders, opening hours, preferences, favorites, ratings, payment methods and bank accounts.

`charging_station_db.sql` contains **synthetic demo data** (300 users and 300 stations). All names, emails, card numbers and bank details in it are fake.

## Project structure

| File | Purpose |
|---|---|
| `index.php` | Landing page |
| `Login.php`, `SignUp.php`, `signup_preferences.php`, `signup_credit_card.php` | Login and sign-up flow |
| `map.php` | Interactive station map |
| `recommended_stations.php` | Recommendation engine |
| `station.php`, `order_station.php` | Station page and booking |
| `order_history.php`, `my_stations_orders.php` | Bookings for drivers and for station owners |
| `add_station.php`, `personal_details.php` | Adding a station and managing the personal area |
| `ask_ai.php` | EvBot AI assistant |
| `email_helper.php`, `forgot_password.php` | Email notifications and password reset |

## Run locally

1. Install [WampServer](https://www.wampserver.com/) (or any PHP + MySQL stack).
2. Copy the project folder into `www`.
3. Import `charging_station_db.sql` in phpMyAdmin.
4. Copy `config.example.php` to `config.php` and add your own Gemini API key and Gmail app password. They are needed only for EvBot and for sending emails.
5. Open `http://localhost/<folder-name>/index.php`.

Demo user: `israel@example.com` / `123456`

## Limitations and next steps

This is an academic prototype that runs on localhost:

- There is no kWh data, so pricing is per hour rather than per energy used.
- Payments and the connection to EvEnergy's chargers are simulated.
- Passwords are stored in plain text in the demo database. A production version would hash them.

Next steps: secure payment processing, direct integration with EvEnergy's chargers, security hardening, a larger data set and a gradual pilot with real users.

## Team

- **Gal Ravid** · [LinkedIn](https://www.linkedin.com/in/galravid/)
- **Tammy Gelman**

In collaboration with EvEnergy.
