const express = require('express');
const router = express.Router();
const mongoose = require('mongoose');

const Project = require('../models/Project');
const User = require('../models/User');

function isLeader(project, userId) {
  return String(project.voditelj) === String(userId) ||
         (project.voditelj && String(project.voditelj._id) === String(userId));
}

function isMember(project, userId) {
  return (project.clanoviTima || [])
    .map(x => String(x._id || x))
    .includes(String(userId));
}

// ---------- LIST ROUTES (static) ----------

// LEADER
router.get('/leader', async (req, res, next) => {
  try {
    const myId = req.session.userId;
    const projects = await Project
      .find({ voditelj: myId, archived: false })
      .sort({ createdAt: -1 });

   res.render('projects/index', { projects, title: 'My projects (leader)', canCreate: true });
  } catch (e) { next(e); }
});

// MEMBER
router.get('/member', async (req, res, next) => {
  try {
    const myId = req.session.userId;
    const projects = await Project
      .find({ clanoviTima: myId, archived: false })
      .sort({ createdAt: -1 });

    res.render('projects/index', { projects, title: 'My projects (member)', canCreate: false });

  } catch (e) { next(e); }
});

// LIST (default = leader list, može ostati ovako)
router.get('/', async (req, res, next) => {
  try {
    const myId = req.session.userId;
    const projects = await Project
      .find({ voditelj: myId, archived: false })
      .sort({ createdAt: -1 });

    res.render('projects/index', { projects, title: 'Projects', canCreate: true });
  } catch (e) { next(e); }
});

// NEW (mora biti iznad /:id)
router.get('/new', (req, res) => {
  res.render('projects/new');
});

// CREATE
router.post('/', async (req, res, next) => {
  try {
    const p = new Project({
      naziv: req.body.naziv,
      opis: req.body.opis,
      cijena: Number(req.body.cijena || 0),
      obavljeniPoslovi: req.body.obavljeniPoslovi,
      datumPocetka: req.body.datumPocetka ? new Date(req.body.datumPocetka) : undefined,
      datumZavrsetka: req.body.datumZavrsetka ? new Date(req.body.datumZavrsetka) : undefined,
      voditelj: req.session.userId,
      clanoviTima: []
    });

    await p.save();
    res.redirect(`/projects/${p._id}`);
  } catch (e) { next(e); }
});

// ARCHIVE: svi arhivirani projekti gdje sam voditelj ili član
router.get('/archive', async (req, res, next) => {
  try {
    const myId = req.session.userId;

    const projects = await Project.find({
      archived: true,
      $or: [
        { voditelj: myId },
        { clanoviTima: myId }
      ]
    }).sort({ updatedAt: -1 });

    res.render('projects/archive', { projects, title: 'Archive' });
  } catch (e) { next(e); }
});


// Member edit: samo obavljeniPoslovi
router.get('/:id/work', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');

    if (!isMember(project, req.session.userId) && !isLeader(project, req.session.userId)) {
      return res.status(403).send('Forbidden');
    }

    res.render('projects/work', { project });
  } catch (e) { next(e); }
});

router.post('/:id/work', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');

    if (!isMember(project, req.session.userId) && !isLeader(project, req.session.userId)) {
      return res.status(403).send('Forbidden');
    }

    project.obavljeniPoslovi = req.body.obavljeniPoslovi || '';
    await project.save();

    res.redirect(`/projects/${project._id}`);
  } catch (e) { next(e); }
});

// EDIT (samo voditelj)
router.get('/:id/edit', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');

    if (!isLeader(project, req.session.userId)) return res.status(403).send('Forbidden');
    res.render('projects/edit', { project });
  } catch (e) { next(e); }
});

// UPDATE (samo voditelj) - BITNO: prvo findById pa provjeri prava, onda update
router.post('/:id', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');
    if (!isLeader(project, req.session.userId)) return res.status(403).send('Forbidden');

    project.naziv = req.body.naziv;
    project.opis = req.body.opis;
    project.cijena = Number(req.body.cijena || 0);
    project.obavljeniPoslovi = req.body.obavljeniPoslovi;
    project.datumPocetka = req.body.datumPocetka ? new Date(req.body.datumPocetka) : undefined;
    project.datumZavrsetka = req.body.datumZavrsetka ? new Date(req.body.datumZavrsetka) : undefined;
    project.archived = (req.body.archived === 'on');

    await project.save();
    res.redirect(`/projects/${project._id}`);
  } catch (e) { next(e); }
});

// DELETE (samo voditelj)
router.post('/:id/delete', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');
    if (!isLeader(project, req.session.userId)) return res.status(403).send('Forbidden');

    await Project.findByIdAndDelete(req.params.id);
    res.redirect('/projects');
  } catch (e) { next(e); }
});

// ADD TEAM MEMBER (samo voditelj)
router.post('/:id/team', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');
    if (!isLeader(project, req.session.userId)) return res.status(403).send('Forbidden');

    const memberId = req.body.memberId;
    if (!memberId) return res.redirect(`/projects/${project._id}`);

    // spriječi duplikat
    if (!project.clanoviTima.map(String).includes(String(memberId))) {
      project.clanoviTima.push(memberId);
      await project.save();
    }

    res.redirect(`/projects/${project._id}`);
  } catch (e) { next(e); }
});

// REMOVE TEAM MEMBER (samo voditelj)
router.post('/:id/team/:memberId/delete', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');
    if (!isLeader(project, req.session.userId)) return res.status(403).send('Forbidden');

    project.clanoviTima = project.clanoviTima.filter(m => String(m) !== String(req.params.memberId));
    await project.save();

    res.redirect(`/projects/${project._id}`);
  } catch (e) { next(e); }
});

// DETAILS (TEK NA KRAJU!)
router.get('/:id', async (req, res, next) => {
  try {
    if (!mongoose.isValidObjectId(req.params.id)) return res.status(404).send('Not found');

    const project = await Project
      .findById(req.params.id)
      .populate('clanoviTima')
      .populate('voditelj');

    if (!project) return res.status(404).send('Not found');

    const users = await User.find().sort({ username: 1 });
    const role = isLeader(project, req.session.userId) ? 'leader' : 'member';
    res.render('projects/show', { project, users, role });

  } catch (e) { next(e); }
});

module.exports = router;
