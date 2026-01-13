// routes/projects.js
const express = require('express');
const router = express.Router();
const Project = require('../models/Project');

// LIST - pregled svih projekata
router.get('/', async (req, res, next) => {
  try {
    const projects = await Project.find().sort({ createdAt: -1 });
    res.render('projects/index', { projects });
  } catch (e) { next(e); }
});

// NEW - forma za novi projekt
router.get('/new', (req, res) => {
  res.render('projects/new');
});

// CREATE - spremi novi projekt
router.post('/', async (req, res, next) => {
  try {
    const p = new Project({
      naziv: req.body.naziv,
      opis: req.body.opis,
      cijena: Number(req.body.cijena || 0),
      obavljeniPoslovi: req.body.obavljeniPoslovi,
      datumPocetka: req.body.datumPocetka ? new Date(req.body.datumPocetka) : undefined,
      datumZavrsetka: req.body.datumZavrsetka ? new Date(req.body.datumZavrsetka) : undefined,
    });
    await p.save();
    res.redirect(`/projects/${p._id}`);
  } catch (e) { next(e); }
});

// DETAILS - detalji projekta + članovi tima
router.get('/:id', async (req, res, next) => {
  try {
    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');
    res.render('projects/show', { project });
  } catch (e) { next(e); }
});

// EDIT - forma za uređivanje
router.get('/:id/edit', async (req, res, next) => {
  try {
    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');
    res.render('projects/edit', { project });
  } catch (e) { next(e); }
});

// UPDATE - spremi izmjene
router.post('/:id', async (req, res, next) => {
  try {
    const update = {
      naziv: req.body.naziv,
      opis: req.body.opis,
      cijena: Number(req.body.cijena || 0),
      obavljeniPoslovi: req.body.obavljeniPoslovi,
      datumPocetka: req.body.datumPocetka ? new Date(req.body.datumPocetka) : undefined,
      datumZavrsetka: req.body.datumZavrsetka ? new Date(req.body.datumZavrsetka) : undefined,
    };

    const project = await Project.findByIdAndUpdate(req.params.id, update, { new: true, runValidators: true });
    if (!project) return res.status(404).send('Not found');
    res.redirect(`/projects/${project._id}`);
  } catch (e) { next(e); }
});

// DELETE - brisanje projekta
router.post('/:id/delete', async (req, res, next) => {
  try {
    await Project.findByIdAndDelete(req.params.id);
    res.redirect('/projects');
  } catch (e) { next(e); }
});

// ADD TEAM MEMBER - dodaj člana tima preko forme
router.post('/:id/team', async (req, res, next) => {
  try {
    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');

    project.clanoviTima.push({
      name: req.body.name,
      role: req.body.role,
      email: req.body.email,
    });

    await project.save();
    res.redirect(`/projects/${project._id}`);
  } catch (e) { next(e); }
});

// REMOVE TEAM MEMBER - obriši člana tima
router.post('/:id/team/:memberId/delete', async (req, res, next) => {
  try {
    const project = await Project.findById(req.params.id);
    if (!project) return res.status(404).send('Not found');

    project.clanoviTima = project.clanoviTima.filter(m => String(m._id) !== String(req.params.memberId));
    await project.save();

    res.redirect(`/projects/${project._id}`);
  } catch (e) { next(e); }
});

module.exports = router;
