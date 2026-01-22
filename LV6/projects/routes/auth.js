const express = require('express');
const bcrypt = require('bcrypt');
const User = require('../models/User');

const router = express.Router();

// register form
router.get('/register', (req, res) => res.render('auth/register'));

// register submit
router.post('/register', async (req, res, next) => {
  try {
    const { username, email, password } = req.body;
    const passwordHash = await bcrypt.hash(password, 10);

    const user = await User.create({ username, email, passwordHash });
    req.session.userId = user._id;       // auto login nakon registracije
    res.redirect('/projects');
  } catch (e) { next(e); }
});

// login form
router.get('/login', (req, res) => res.render('auth/login'));

// login submit
router.post('/login', async (req, res, next) => {
  try {
    const { username, password } = req.body;

    const user = await User.findOne({ username });
    if (!user) return res.status(401).send('Wrong credentials');

    const ok = await bcrypt.compare(password, user.passwordHash);
    if (!ok) return res.status(401).send('Wrong credentials');

    req.session.userId = user._id;
    res.redirect('/projects');
  } catch (e) { next(e); }
});

// logout
router.post('/logout', (req, res) => {
  req.session.destroy(() => res.redirect('/auth/login'));
});

module.exports = router;
