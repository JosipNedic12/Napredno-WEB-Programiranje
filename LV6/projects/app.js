var createError = require('http-errors');
var express = require('express');
var path = require('path');
var cookieParser = require('cookie-parser');
var logger = require('morgan');

var indexRouter = require('./routes/index');
var usersRouter = require('./routes/users');

var app = express();

// view engine setup
app.set('views', path.join(__dirname, 'views'));
app.set('view engine', 'ejs');

app.use(logger('dev'));
app.use(express.json());
app.use(express.urlencoded({ extended: false }));
app.use(cookieParser());
app.use(express.static(path.join(__dirname, 'public')));

app.use('/', indexRouter);
app.use('/users', usersRouter);

const session = require('express-session');

app.use(session({
  secret: 'change-this-secret',
  resave: false,
  saveUninitialized: false
}));

const User = require('./models/User');

app.use(async (req, res, next) => {
  if (req.session.userId) {
    res.locals.currentUser = await User.findById(req.session.userId);
  } else {
    res.locals.currentUser = null;
  }
  next();
});

const mongoose = require('mongoose');

mongoose.connect('mongodb://127.0.0.1:27017/projectsdb')
  .then(() => console.log('Mongo connected'))
  .catch(err => console.error('Mongo error', err));
  

const projectsRouter = require('./routes/projects');
// app.use('/projects', projectsRouter);
const authRouter = require('./routes/auth');
app.use('/auth', authRouter);

const requireLogin = require('./middleware/requireLogin');
app.use('/projects', requireLogin, projectsRouter);

app.get('/', (req, res) => res.redirect('/projects'));

// catch 404 and forward to error handler
app.use(function(req, res, next) {
  next(createError(404));
});

// error handler
app.use(function(err, req, res, next) {
  // set locals, only providing error in development
  res.locals.message = err.message;
  res.locals.error = req.app.get('env') === 'development' ? err : {};

  // render the error page
  res.status(err.status || 500);
  res.render('error');
});

module.exports = app;
